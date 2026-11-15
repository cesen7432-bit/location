# Cómo generar el trazado de una ruta desde el frontend

Guía para el frontend del panel admin. Referencia completa de endpoints y respuestas: [API.md](API.md).

El **trazado** es la línea real de la ruta por las calles. Con él la API calcula el avance del camión, los desvíos, qué ubicaciones de usuarios atiende cada ruta y cuándo avisarles. Todos los métodos requieren token de **admin**.

| Método | Cuándo usarlo | Endpoints |
|---|---|---|
| **1. Grabar con el celular, por tramos** (recomendado) | Recorrer la ruta en carro con el panel abierto en el celular | `POST /admin/routes/{id}/recordings` → `POST /admin/recordings/{id}/points` (varias veces) → `POST /admin/recordings/{id}/finish` |
| 2. Grabar con el celular y enviar al final | Recorridos cortos | `PUT /admin/routes/{id}/geometry` con `path` + `accuracy` + `recorded_at` |
| 3. Dibujar en el mapa | Rutas nuevas que aún no se han recorrido | `PUT /admin/routes/{id}/geometry` con `path` |
| 4. Subir un archivo | Hay un GPX/KML/GeoJSON (p. ej. exportado del CAD municipal) | `PUT /admin/routes/{id}/geometry` con `file` |
| 5. Usar el GPS del camión | El recolector ya hizo la ruta con el dispositivo | `POST /admin/routes/{id}/geometry/from-tracking` con `run_id` |

En todos los casos la API limpia y simplifica la línea y responde con el trazado guardado (`path: [{lat, lng}]`, `distance_m`, `served_locations`).

## Requisitos para grabar desde el navegador

- La página debe servirse por **HTTPS** (el navegador no da GPS en HTTP).
- El usuario debe **aceptar el permiso de ubicación**.
- **Mantener la pantalla encendida**: con la pantalla bloqueada el navegador deja de recibir posiciones. Use la *Wake Lock API* (incluida abajo) y pida al operador no cambiar de app.
- Recorrer la ruta **en el orden y sentido reales**, de inicio a fin. Detener la grabación al terminar la parte urbana (no incluir el viaje al relleno).

## Método 1 · Grabar por tramos (recomendado)

Los puntos se envían cada 15 s, así un recorrido largo no depende de una sola petición al final. Si se cae la red, el tramo se reintenta (reenviar es seguro: la API no duplica puntos con el mismo `recorded_at`). Si se cierra la pestaña, al volver a iniciar se **retoma** la misma grabación.

```ts
type RecPoint = { lat: number; lng: number; accuracy: number; recorded_at: string };

// `api` es el helper de API.md (sección "Tipos TypeScript sugeridos"):
// devuelve `result` o lanza un Error con { status, errors: result }.
export class RouteRecorder {
  recordingId: number | null = null;
  private buffer: RecPoint[] = [];
  private watchId: number | null = null;
  private timer: ReturnType<typeof setInterval> | null = null;
  private wakeLock: WakeLockSentinel | null = null;

  constructor(private routeId: number) {
    const saved = localStorage.getItem(this.key);
    if (saved) Object.assign(this, JSON.parse(saved)); // puntos sin enviar de una sesión anterior
  }

  private get key() { return `route-recording:${this.routeId}`; }
  private persist() {
    localStorage.setItem(this.key, JSON.stringify({ recordingId: this.recordingId, buffer: this.buffer }));
  }

  /** Abre (o retoma) la grabación y empieza a leer el GPS. */
  async start(onPoint?: (p: RecPoint) => void) {
    try {
      const rec = await api<{ id: number }>(`/admin/routes/${this.routeId}/recordings`, { method: 'POST', body: '{}' });
      this.recordingId = rec.id;
    } catch (e: any) {
      if (e.status !== 409) throw e;
      this.recordingId = e.errors.id; // ya había una grabación abierta: se retoma
    }
    this.persist();

    this.wakeLock = await navigator.wakeLock?.request('screen').catch(() => null) ?? null;

    this.watchId = navigator.geolocation.watchPosition(
      (pos) => {
        const p: RecPoint = {
          lat: pos.coords.latitude,
          lng: pos.coords.longitude,
          accuracy: pos.coords.accuracy,
          recorded_at: new Date(pos.timestamp).toISOString(),
        };
        this.buffer.push(p);
        this.persist();
        onPoint?.(p); // p. ej. dibujar la línea en vivo
      },
      (err) => console.warn('GPS', err.message),
      { enableHighAccuracy: true, maximumAge: 0, timeout: 20000 },
    );

    this.timer = setInterval(() => this.flush(), 15000);
    document.addEventListener('visibilitychange', this.onVisibility);
  }

  private onVisibility = () => { if (document.visibilityState === 'hidden') this.flush(); };

  /** Envía los puntos pendientes. Si falla, quedan en el buffer y se reintentan. */
  async flush() {
    if (!this.recordingId || this.buffer.length === 0) return;
    const chunk = this.buffer.slice(0, 300); // keepalive limita el cuerpo a ~64 KB
    try {
      await api(`/admin/recordings/${this.recordingId}/points`, {
        method: 'POST',
        body: JSON.stringify({ points: chunk }),
        keepalive: true,
      });
      this.buffer.splice(0, chunk.length);
      this.persist();
      if (this.buffer.length) await this.flush();
    } catch (e) {
      console.warn('Tramo no enviado, se reintentará', e);
    }
  }

  /** Línea limpia con lo recibido hasta ahora (para revisar antes de guardar). */
  preview() {
    return api<{ preview: { distance_m: number; path: { lat: number; lng: number }[] } }>(
      `/admin/recordings/${this.recordingId}`,
    );
  }

  /** Detiene el GPS, envía lo pendiente y guarda el trazado de la ruta. */
  async finish() {
    this.stop();
    await this.flush();
    if (this.buffer.length) throw new Error('Quedan puntos sin enviar. Revise la conexión y reintente.');
    const res = await api<{ geometry: { distance_m: number; path: { lat: number; lng: number }[] } }>(
      `/admin/recordings/${this.recordingId}/finish`,
      { method: 'POST' },
    );
    localStorage.removeItem(this.key);
    return res.geometry;
  }

  /** Descarta la grabación (el trazado actual de la ruta no cambia). */
  async cancel() {
    this.stop();
    if (this.recordingId) await api(`/admin/recordings/${this.recordingId}`, { method: 'DELETE' });
    localStorage.removeItem(this.key);
  }

  private stop() {
    if (this.watchId !== null) navigator.geolocation.clearWatch(this.watchId);
    if (this.timer) clearInterval(this.timer);
    document.removeEventListener('visibilitychange', this.onVisibility);
    this.wakeLock?.release();
    this.watchId = null;
    this.timer = null;
    this.wakeLock = null;
  }
}
```

Uso en la pantalla de la ruta:

```ts
const recorder = new RouteRecorder(routeId);

startButton.onclick = () => recorder.start((p) => liveLine.addLatLng([p.lat, p.lng]));
previewButton.onclick = async () => drawPreview((await recorder.preview()).preview.path);
finishButton.onclick = async () => {
  try {
    const geometry = await recorder.finish();
    alert(`Trazado guardado: ${(geometry.distance_m / 1000).toFixed(1)} km`);
  } catch (e: any) {
    // 422: muy pocos puntos válidos. La grabación sigue abierta: se puede seguir grabando.
    alert(e.message);
  }
};
cancelButton.onclick = () => recorder.cancel();
```

Si al abrir la pantalla `localStorage` tiene una grabación para esa ruta, muestre **"Continuar grabación"**: `recorder.start()` la retoma y envía los puntos que quedaron pendientes.

## Método 2 · Grabar y enviar todo al final

Para recorridos cortos. Guarde los puntos igual que arriba y al terminar envíelos en una sola petición. Al incluir `accuracy` y `recorded_at`, la API aplica la misma limpieza que en el método 1:

```ts
await api(`/admin/routes/${routeId}/geometry`, {
  method: 'PUT',
  body: JSON.stringify({ path: points }), // [{ lat, lng, accuracy, recorded_at }]
});
```

## Método 3 · Dibujar en el mapa

El operador hace clic sobre las calles en orden. Sin `recorded_at` no se filtra nada (solo se simplifica):

```ts
await api(`/admin/routes/${routeId}/geometry`, {
  method: 'PUT',
  body: JSON.stringify({ path: clicks.map((c) => ({ lat: c.lat, lng: c.lng })), simplify: false }),
});
```

> Sugerencia: para que la línea siga las calles, ajuste los clics con un servicio de rutas (p. ej. OSRM sobre OpenStreetMap) antes de enviarlos.

## Método 4 · Subir un archivo

```ts
const form = new FormData();
form.append('file', fileInput.files[0]); // .gpx, .kml, .geojson o .json (máx. 10 MB)
form.append('_method', 'PUT');            // multipart se envía como POST con _method

await fetch(`${BASE_URL}/api/admin/routes/${routeId}/geometry`, {
  method: 'POST',
  headers: { Accept: 'application/json', Authorization: `Bearer ${token}` }, // sin Content-Type: lo pone el navegador
  body: form,
});
```

## Método 5 · Usar el GPS del camión

Cuando el recolector ya recorrió la ruta (la inició y la finalizó desde su app), use ese recorrido:

```ts
await api(`/admin/routes/${routeId}/geometry/from-tracking`, {
  method: 'POST',
  body: JSON.stringify({ run_id: runId }), // o { collector_id, date: '2026-11-09', from: '07:00', to: '11:30' }
});
```

## Después de guardar

- `GET /api/admin/routes/{id}/geometry` devuelve el trazado para mostrarlo; `GET /api/public/routes/{id}/geometry` es la versión pública.
- `served_locations` indica cuántas ubicaciones de usuarios quedaron atendidas por la ruta (a ≤ 150 m de la línea).
- Para corregirlo, vuelva a grabar o cargue otro trazado: el nuevo reemplaza al anterior.
