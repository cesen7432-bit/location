<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int   $along_m    Metro de la ruta donde queda la ubicación
 * @property float $distance_m Distancia de la ubicación a la línea de la ruta
 */
class LocationRouteMatch extends Model
{
    protected $fillable = [
        'user_location_id',
        'route_id',
        'along_m',
        'distance_m',
        'last_notified_run_id',
        'last_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'along_m'          => 'integer',
            'distance_m'       => 'float',
            'last_notified_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(UserLocation::class, 'user_location_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
