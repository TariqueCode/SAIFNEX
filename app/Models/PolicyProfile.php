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
        'network_id', 'name', 'key', 'preset_key', 'template_profile_id', 'source', 'status',
        'description', 'is_default', 'is_template',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_template' => 'boolean',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(self::class, 'template_profile_id');
    }

    public function derivedProfiles(): HasMany
    {
        return $this->hasMany(self::class, 'template_profile_id');
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
