<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Block extends Model
{
    protected $fillable = [
        'item_id',
        'orig_id',
        'name',
        'field_1',
        'field_2',
        'field_3',
        'media',
    ];

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Block::class, 'orig_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(Block::class, 'orig_id');
    }
}
