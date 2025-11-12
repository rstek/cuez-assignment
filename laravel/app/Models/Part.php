<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Part extends Model
{
    protected $fillable = [
        'episode_id',
        'orig_id',
        'name',
    ];

    public function episode(): BelongsTo
    {
        return $this->belongsTo(Episode::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }

    public function original(): BelongsTo
    {
        return $this->belongsTo(Part::class, 'orig_id');
    }

    public function duplicates(): HasMany
    {
        return $this->hasMany(Part::class, 'orig_id');
    }
}
