<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

class RouteRecording extends Model
{
    public const RECORDING = 'recording';
    public const FINISHED  = 'finished';
    public const CANCELLED = 'cancelled';

    protected $fillable = [
        'route_id',
        'user_id',
        'status',
        'started_at',
        'finished_at',
        'point_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'started_at'  => 'datetime',
            'finished_at' => 'datetime',
            'point_count' => 'integer',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRecording(): bool
    {
        return $this->status === self::RECORDING;
    }

    public function points()
    {
        return DB::table('route_recording_points')
            ->where('route_recording_id', $this->id)
            ->orderBy('recorded_at');
    }

    public function toApi(): array
    {
        $range = DB::table('route_recording_points')
            ->where('route_recording_id', $this->id)
            ->selectRaw('MIN(recorded_at) as first_at, MAX(recorded_at) as last_at')
            ->first();

        return [
            'id'             => $this->id,
            'status'         => $this->status,
            'route'          => $this->relationLoaded('route') && $this->route
                ? ['id' => $this->route->id, 'name' => $this->route->name, 'code' => $this->route->code]
                : ['id' => $this->route_id],
            'started_at'     => $this->started_at?->toIso8601String(),
            'finished_at'    => $this->finished_at?->toIso8601String(),
            'point_count'    => $this->point_count,
            'first_point_at' => $range?->first_at ? \Illuminate\Support\Carbon::parse($range->first_at)->toIso8601String() : null,
            'last_point_at'  => $range?->last_at ? \Illuminate\Support\Carbon::parse($range->last_at)->toIso8601String() : null,
            'notes'          => $this->notes,
        ];
    }
}
