<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int         $id
 * @property int         $route_id
 * @property string      $day_of_week  monday|tuesday|wednesday|thursday|friday|saturday|sunday
 * @property string      $start_time
 * @property string|null $end_time
 * @property bool        $active
 */
class Schedule extends Model
{
    public const DAYS = [
        'monday', 'tuesday', 'wednesday', 'thursday',
        'friday', 'saturday', 'sunday',
    ];

    public const DAYS_ES = [
        'monday'    => 'Lunes',
        'tuesday'   => 'Martes',
        'wednesday' => 'Miércoles',
        'thursday'  => 'Jueves',
        'friday'    => 'Viernes',
        'saturday'  => 'Sábado',
        'sunday'    => 'Domingo',
    ];

    protected $fillable = [
        'route_id',
        'day_of_week',
        'start_time',
        'end_time',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'active' => 'boolean',
        ];
    }

    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }

    public function getDayNameAttribute(): string
    {
        return self::DAYS_ES[$this->day_of_week] ?? $this->day_of_week;
    }
}
