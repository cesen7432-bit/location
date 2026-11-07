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
    protected $fillable = [
        'name',
        'description',
        'zone',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
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
