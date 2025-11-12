<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @method static Episode create(array $attributes = [])
 */
class Episode extends Model
{
    /** @use HasFactory<\Database\Factories\EpisodeFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'orig_id',
    ];

    // Children
    public function parts(): HasMany
    {
        return $this->hasMany(Part::class);
    }

    // Self-referential: original this episode was copied from
    public function original(): BelongsTo
    {
        return $this->belongsTo(Episode::class, 'orig_id');
    }

    // Self-referential: duplicates that came from this episode
    public function duplicates(): HasMany
    {
        return $this->hasMany(Episode::class, 'orig_id');
    }

    // Duplications where this is the source episode
    public function duplications(): HasMany
    {
        return $this->hasMany(EpisodeDuplication::class, 'episode_id');
    }

    // Duplications where this is the target (new) episode
    public function duplicationsAsTarget(): HasMany
    {
        return $this->hasMany(EpisodeDuplication::class, 'new_episode_id');
    }
}
