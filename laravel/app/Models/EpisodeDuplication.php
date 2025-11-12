<?php

namespace App\Models;

use App\Jobs\DuplicateBlocks;
use App\Jobs\DuplicateEpisode;
use App\Jobs\DuplicateItems;
use App\Jobs\DuplicateParts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Bus;
use Throwable;

class EpisodeDuplication extends Model
{
    protected $table = 'duplications';

    protected $fillable = [
        'episode_id',
        'new_episode_id',
        'status',
        'progress',
    ];

    public function getNewEpisodeId(): ?int
    {
        return $this->new_episode_id ? (int) $this->new_episode_id : null;
    }


    protected function casts(): array
    {
        return [
            'progress' => 'array',
        ];
    }

    public function episode()
    {
        return $this->belongsTo(Episode::class);
    }

    public function newEpisode()
    {
        return $this->belongsTo(Episode::class, 'new_episode_id');
    }


    public function addProgress(string $key, int $increment): void
    {
        $progress = $this->progress ?? [];
        $progress[$key] = ($progress[$key] ?? 0) + $increment;
        $this->progress = $progress;
        $this->save();
    }

    public function createJobChain()
    {
        Bus::chain([
            new DuplicateEpisode($this->id, $this->episode_id),
            new DuplicateParts($this->id, $this->episode_id),
            new DuplicateItems($this->id, $this->episode_id),
            new DuplicateBlocks($this->id, $this->episode_id),
        ])->catch(function (Throwable $e) {
            // Handle the exception
            $this->failed($e);
        })->onConnection('sqs')->onQueue('duplicate_episodes')->dispatch();
    }

    public function failed(Throwable $e): void
    {
        $this->update(['status' => 'failed']);
        $this->log('error', 'Duplication failed', [
            'exception' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
            'exception_class' => get_class($e),
        ]);
        // We could potentially store the error/messages in the database as well,
        // if we expand our EpisodeDuplication model.
        // Match exception types to user-friendly messages which we pass in the event.

        // Dispatch event Duplication.failed
    }

    public static function createForEpisode(Episode $episode): EpisodeDuplication
    {
        return self::create([
            'episode_id' => $episode->id,
            'status' => 'pending',
            'progress' => [],
        ]);
    }
}
