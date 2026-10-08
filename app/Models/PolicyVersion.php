<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PolicyVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'profile_id', 'version', 'status', 'snapshot',
        'snapshot_hash', 'created_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'published_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(PolicyProfile::class, 'profile_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
