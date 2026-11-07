<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CollectorRoute extends Model
{
    protected $fillable = [
        'collector_id',
        'route_id',
        'assigned_date',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'assigned_date' => 'date',
            'active' => 'boolean',
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
