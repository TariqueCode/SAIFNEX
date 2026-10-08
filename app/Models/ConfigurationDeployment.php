<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationDeployment extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'configuration_version_id',
        'network_node_id',
        'status',
        'requested_at',
        'started_at',
        'completed_at',
        'error_message',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function configuration(): BelongsTo
    {
        return $this->belongsTo(ConfigurationVersion::class, 'configuration_version_id');
    }

    public function node(): BelongsTo
    {
        return $this->belongsTo(NetworkNode::class, 'network_node_id');
    }
}
