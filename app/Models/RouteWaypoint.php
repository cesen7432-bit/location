<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $route_id
 * @property int         $sequence
 * @property string      $type  start|pause|landfill|resume|end
 * @property float       $lat
 * @property float       $lng
 * @property string|null $notes
 */
class RouteWaypoint extends Model
{
    public const TYPES = ['start', 'pause', 'landfill', 'resume', 'end'];

    protected $fillable = ['route_id', 'sequence', 'type', 'lat', 'lng', 'notes'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'lat'      => 'float',
            'lng'      => 'float',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function toApi(): array
    {
        return [
            'id'       => $this->id,
            'sequence' => $this->sequence,
            'type'     => $this->type,
            'lat'      => $this->lat,
            'lng'      => $this->lng,
            'notes'    => $this->notes,
        ];
    }
}
