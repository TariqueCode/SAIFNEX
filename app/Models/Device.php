<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id', 'name', 'type', 'identifier', 'profile_key',
        'status', 'capabilities', 'first_seen_at', 'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'capabilities' => 'array',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(DeviceEnrollment::class);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class);
    }
}
