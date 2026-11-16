<?php

namespace App\Models;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $name
 * @property int|null    $collector_id
 * @property string      $key_prefix
 * @property string      $key_hash
 * @property bool        $active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class ApiKey extends Model
{
    public const PREFIX = 'ak_';

    protected $fillable = [
        'name',
        'collector_id',
        'key_prefix',
        'key_hash',
        'active',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'active'       => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Generate a new random plain-text key. */
    public static function generatePlainKey(): string
    {
        return self::PREFIX . Str::random(48);
    }

    public static function hashKey(string $plain): string
    {
        return hash('sha256', $plain);
    }

    // Desactivar al recolector tarda hasta este tiempo en bloquear sus pings
    public const CACHE_TTL_S = 60;

    protected static function booted(): void
    {
        $forget = fn (ApiKey $key) => self::cacheStore()->forget(self::cacheKey($key->key_hash));

        static::saved($forget);
        static::deleted($forget);
    }

    public static function cacheStore(): Repository
    {
        return Cache::store(config('cache.hot_store'));
    }

    public static function cacheKey(string $hash): string
    {
        return "api-key:{$hash}";
    }

    /** Busca la key por su hash; las inexistentes no se guardan para no llenar la caché. */
    public static function cached(string $hash): ?self
    {
        $store = self::cacheStore();

        if ($key = $store->get(self::cacheKey($hash))) {
            return $key;
        }

        $key = self::with('collector')->where('key_hash', $hash)->first();

        if ($key) {
            $store->put(self::cacheKey($hash), $key, self::CACHE_TTL_S);
        }

        return $key;
    }

    /** Assign a fresh key to this model and return the plain value (shown only once). */
    public function fillNewKey(): string
    {
        $plain = self::generatePlainKey();
        $this->key_hash   = self::hashKey($plain);
        $this->key_prefix = substr($plain, 0, 10);

        return $plain;
    }
}
