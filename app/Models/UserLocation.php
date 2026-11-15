<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserLocation extends Model
{
    public const DEFAULT_NOTIFY_DISTANCE = 500;
    public const MIN_NOTIFY_DISTANCE     = 100;
    public const MAX_NOTIFY_DISTANCE     = 2000;

    // Pasado este tiempo, "basura afuera" deja de mostrarse aunque nadie la haya recogido
    public const GARBAGE_OUT_TTL_HOURS = 24;

    protected $fillable = [
        'user_id',
        'label',
        'address',
        'latitude',
        'longitude',
        'notify_enabled',
        'notify_distance_m',
        'garbage_out',
        'garbage_out_at',
        'garbage_collected_at',
    ];

    protected $attributes = [
        'notify_enabled'    => true,
        'notify_distance_m' => self::DEFAULT_NOTIFY_DISTANCE,
        'garbage_out'       => false,
    ];

    protected function casts(): array
    {
        return [
            'latitude'             => 'float',
            'longitude'            => 'float',
            'notify_enabled'       => 'boolean',
            'notify_distance_m'    => 'integer',
            'garbage_out'          => 'boolean',
            'garbage_out_at'       => 'datetime',
            'garbage_collected_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function routeMatches(): HasMany
    {
        return $this->hasMany(LocationRouteMatch::class);
    }

    public function hasGarbageOut(): bool
    {
        return $this->garbage_out
            && $this->garbage_out_at
            && $this->garbage_out_at->gt(now()->subHours(self::GARBAGE_OUT_TTL_HOURS));
    }
}
