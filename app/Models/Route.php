<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $description
 * @property string|null $zone
 * @property bool        $active
 * @property int|null    $points_count
 */
class Route extends Model
{
    public const SHIFTS = ['day', 'night'];

    protected $fillable = [
        'name',
        'code',
        'description',
        'zone',
        'shift',
        'active',
        'geometry',
        'geometry_source',
        'distance_m',
    ];

    // El trazado pesa varios KB: solo lo devuelven los endpoints de geometría
    protected $hidden = ['geometry'];

    protected function casts(): array
    {
        return [
            'active'     => 'boolean',
            'geometry'   => 'array',
            'distance_m' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saved(function (Route $route) {
            if ($route->wasChanged('geometry')) {
                \App\Services\RouteGeometryService::forget($route->id);
            }
        });

        static::deleted(fn (Route $route) => \App\Services\RouteGeometryService::forget($route->id));
    }

    public function hasGeometry(): bool
    {
        return !empty($this->geometry['coordinates']) && count($this->geometry['coordinates']) >= 2;
    }

    public function runs(): HasMany
    {
        return $this->hasMany(RouteRun::class);
    }

    public function locationMatches(): HasMany
    {
        return $this->hasMany(LocationRouteMatch::class);
    }

    public function points(): HasMany
    {
        return $this->hasMany(RoutePoint::class)->orderBy('sequence');
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class)->orderByRaw(
            "FIELD(day_of_week, 'monday','tuesday','wednesday','thursday','friday','saturday','sunday')"
        );
    }

    public function collectorRoutes(): HasMany
    {
        return $this->hasMany(CollectorRoute::class);
    }

    public function tracking(): HasMany
    {
        return $this->hasMany(CollectorTracking::class);
    }
}
