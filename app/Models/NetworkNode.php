<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NetworkNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'name',
        'type',
        'region',
        'endpoint',
        'status',
        'version',
        'config_version',
        'capabilities',
        'credential_reference',
        'last_seen_at',
    ];

    protected $casts = [
        'capabilities' => 'array',
        'last_seen_at' => 'datetime',
        'credential_rotated_at' => 'datetime',
    ];

    protected $hidden = [
        'credential_reference',
        'credential_hash',
    ];

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(ConfigurationDeployment::class);
    }
}
