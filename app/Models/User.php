<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property int    $id
 * @property string $name
 * @property string $email
 * @property string $role  'user'|'collector'|'admin'
 * @property string|null $phone
 * @property bool   $active
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, HasApiTokens;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'phone',
        'active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'active' => 'boolean',
        ];
    }

    public function isCollector(): bool
    {
        return $this->role === 'collector';
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function collectorRoutes(): HasMany
    {
        return $this->hasMany(CollectorRoute::class, 'collector_id');
    }

    public function tracking(): HasMany
    {
        return $this->hasMany(CollectorTracking::class, 'collector_id');
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(ApiKey::class, 'collector_id');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CollectorSession::class, 'collector_id');
    }

    public function todaySession(): HasMany
    {
        return $this->hasMany(CollectorSession::class, 'collector_id')
            ->where('tracking_date', today());
    }
}
