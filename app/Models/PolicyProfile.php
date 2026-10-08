<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PolicyProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id', 'name', 'key', 'source', 'status',
        'description', 'is_default',
    ];

    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }

    public function rules(): HasMany
    {
        return $this->hasMany(PolicyRule::class, 'profile_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class, 'profile_id');
    }
}
