<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * @property int         $id
 * @property string      $name
 * @property int|null    $collector_id
 * @property string      $key_prefix
 * @property string      $key_hash
 * @property bool        $active
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $expires_at
 */
class ApiKey extends Model
{
    public const PREFIX = 'ak_';

    protected $fillable = [
        'name',
        'collector_id',
        'key_prefix',
        'key_hash',
        'active',
        'last_used_at',
        'expires_at',
        'created_by',
    ];

    protected $hidden = ['key_hash'];

    protected function casts(): array
    {
        return [
            'active'       => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at'   => 'datetime',
        ];
    }

    public function collector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'collector_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Generate a new random plain-text key. */
    public static function generatePlainKey(): string
    {
        return self::PREFIX . Str::random(48);
    }

    public static function hashKey(string $plain): string
    {
        return hash('sha256', $plain);
    }

    /** Assign a fresh key to this model and return the plain value (shown only once). */
    public function fillNewKey(): string
    {
        $plain = self::generatePlainKey();
        $this->key_hash   = self::hashKey($plain);
        $this->key_prefix = substr($plain, 0, 10);

        return $plain;
    }
}
