<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RouteRun extends Model
{
    public const IN_PROGRESS = 'in_progress';
    public const COMPLETED   = 'completed';

    protected $fillable = [
        'collector_id',
        'route_id',
        'status',
        'started_at',
        'start_latitude',
        'start_longitude',
        'start_source',
        'ended_at',
        'end_latitude',
        'end_longitude',
        'end_source',
        'closed_by',
        'ping_count',
        'distance_m',
        'max_progress',
        'landfill_trips',
        'detour_state',
        'detour_started_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at'      => 'datetime',
            'ended_at'        => 'datetime',
            'start_latitude'  => 'float',
            'start_longitude' => 'float',
            'end_latitude'    => 'float',
            'end_longitude'   => 'float',
            'ping_count'      => 'integer',
            'distance_m'      => 'integer',
            'max_progress'    => 'float',
            'landfill_trips'  => 'integer',
            'detour_started_at' => 'datetime',
        ];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function pings(): HasMany
    {
        return $this->hasMany(CollectorTracking::class, 'run_id');
    }

    public function isInProgress(): bool
    {
        return $this->status === self::IN_PROGRESS;
    }

    public function toApi(): array
    {
        $end = $this->ended_at ?? ($this->isInProgress() ? now() : null);

        return [
            'id'        => $this->id,
            'status'    => $this->status,
            'collector' => $this->relationLoaded('collector') && $this->collector
                ? ['id' => $this->collector->id, 'name' => $this->collector->name]
                : ['id' => $this->collector_id],
            'route' => $this->relationLoaded('route') && $this->route
                ? ['id' => $this->route->id, 'name' => $this->route->name, 'code' => $this->route->code]
                : ['id' => $this->route_id],
            'started_at' => $this->started_at?->toIso8601String(),
            'start'      => [
                'latitude'  => $this->start_latitude,
                'longitude' => $this->start_longitude,
                'source'    => $this->start_source,
            ],
            'ended_at' => $this->ended_at?->toIso8601String(),
            'end'      => $this->ended_at ? [
                'latitude'  => $this->end_latitude,
                'longitude' => $this->end_longitude,
                'source'    => $this->end_source,
            ] : null,
            'duration_s'   => $end ? (int) $this->started_at->diffInSeconds($end) : null,
            'distance_m'   => $this->distance_m,
            'ping_count'   => $this->ping_count,
            'max_progress' => $this->max_progress,
            'landfill_trips' => (int) $this->landfill_trips,
            'closed_by'    => $this->closed_by,
            'notes'        => $this->notes,
        ];
    }
}
