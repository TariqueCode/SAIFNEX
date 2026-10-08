<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    use HasFactory;

    protected $fillable = [
        'network_id', 'name', 'timezone', 'definition', 'enabled',
    ];

    protected function casts(): array
    {
        return [
            'definition' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function network(): BelongsTo
    {
        return $this->belongsTo(Network::class);
    }
}
