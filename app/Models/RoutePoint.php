<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $route_id
 * @property int         $neighborhood_id
 * @property int         $sequence
 * @property string|null $notes
 */
class RoutePoint extends Model
{
    protected $fillable = [
        'route_id',
        'neighborhood_id',
        'sequence',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(Neighborhood::class);
    }

    /** Shortcut: coordinates from the linked neighborhood */
    public function getLatitudeAttribute(): ?float
    {
        return $this->neighborhood?->latitude;
    }

    public function getLongitudeAttribute(): ?float
    {
        return $this->neighborhood?->longitude;
    }
}
