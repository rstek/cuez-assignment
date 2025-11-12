<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Item extends Model
{
    protected $fillable = [
        'part_id',
        'orig_id',
        'name',
    ];

    public function part(): BelongsTo
    {
        return $this->belongsTo(Part::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(Block::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'orig_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(Item::class, 'orig_id');
    }
}
