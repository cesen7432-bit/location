<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $municipality
 * @property string|null $zone
 * @property float       $latitude
 * @property float       $longitude
 * @property string|null $notes
 * @property bool        $active
 */
class Neighborhood extends Model
{
    protected $fillable = [
        'name',
        'municipality',
        'zone',
        'latitude',
        'longitude',
        'notes',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'latitude'  => 'float',
            'longitude' => 'float',
            'active'    => 'boolean',
        ];
    }

    public function routePoints(): HasMany
    {
        return $this->hasMany(RoutePoint::class);
    }
}
