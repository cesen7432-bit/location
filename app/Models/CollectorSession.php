<?php

namespace App\Models;

use App\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $collector_id
 * @property int         $route_id
 * @property \Carbon\Carbon $tracking_date
 * @property float|null  $current_latitude
 * @property float|null  $current_longitude
 * @property string      $status  'on_route'|'paused'|'completed'
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $last_seen_at
 * @property float|null  $last_broadcast_lat
 * @property float|null  $last_broadcast_lng
 * @property \Carbon\Carbon|null $last_broadcast_at
 */
class CollectorSession extends Model
{
    protected $fillable = [
        'collector_id',
        'route_id',
        'run_id',
        'tracking_date',
        'current_latitude',
        'current_longitude',
        'along_m',
        'segment_index',
        'progress',
        'distance_to_route_m',
        'off_route',
        'off_route_count',
        'status',
        'started_at',
        'last_seen_at',
        'last_broadcast_lat',
        'last_broadcast_lng',
        'last_broadcast_at',
    ];

    protected function casts(): array
    {
        return [
            'tracking_date'      => DateOnly::class,
            'started_at'         => 'datetime',
            'last_seen_at'       => 'datetime',
            'last_broadcast_at'  => 'datetime',
            'current_latitude'   => 'float',
            'current_longitude'  => 'float',
            'last_broadcast_lat' => 'float',
            'last_broadcast_lng' => 'float',
            'along_m'            => 'integer',
            'segment_index'      => 'integer',
            'progress'           => 'float',
            'distance_to_route_m'=> 'float',
            'off_route'          => 'boolean',
            'off_route_count'    => 'integer',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(RouteRun::class, 'run_id');
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
