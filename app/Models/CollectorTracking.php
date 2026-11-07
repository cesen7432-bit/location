<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectorTracking extends Model
{
    protected $table = 'collector_tracking';

    protected $fillable = [
        'collector_id',
        'route_id',
        'latitude',
        'longitude',
        'accuracy',
        'tracking_date',
        'recorded_at',
    ];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'tracking_date' => 'date',
            'recorded_at'   => 'datetime',
            'latitude'      => 'float',
            'longitude'     => 'float',
            'accuracy'      => 'float',
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
}
