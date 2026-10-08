<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConfigurationVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id',
        'version',
        'status',
        'schema_version',
        'snapshot',
        'snapshot_hash',
        'signature',
        'signature_algorithm',
        'generated_at',
        'validated_at',
        'staged_at',
        'published_at',
        'activated_at',
        'error_message',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'generated_at' => 'datetime',
        'validated_at' => 'datetime',
        'staged_at' => 'datetime',
        'published_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
