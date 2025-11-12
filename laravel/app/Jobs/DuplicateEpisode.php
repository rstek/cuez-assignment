<?php

namespace App\Jobs;

use App\Exceptions\OriginalEpisodeNotFound;
use App\Models\Episode;
use Illuminate\Support\Facades\DB;

class DuplicateEpisode extends DuplicateBase {

    /**
     * Handle the duplication of the episode.
     */
    protected function handleDuplication(): void {
        // OTEL: create span for loading original episode

        $episode = Episode::query()->where('id', $this->orgEpisodeId)->first();

        if (!$episode) {
            $this->log('error', 'Original episode not found', [
                'episode_id' => $this->orgEpisodeId,
            ]);
            throw new OriginalEpisodeNotFound($this->orgEpisodeId);
        }

        $this->log('debug', 'Original episode loaded', [
            'episode_title' => $episode->title,
        ]);
        // OTEL: end episode load span

        // OTEL: create span for transaction

        DB::transaction(function() use ($episode) {
            $newEpisode = $episode->replicate(['id']);
            $newEpisode->orig_id = $episode->id;
            $newEpisode->save();

            $this->log('debug', 'New episode created', [
                'new_episode_id' => $newEpisode->id,
                'new_episode_title' => $newEpisode->title,
            ]);

            $this->episodeDuplication->update(['new_episode_id' => $newEpisode->id]);
            // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'episode', message => 'episode duplicated', amount => 1

            $this->log('info', 'Episode duplication completed', [
                'new_episode_id' => $newEpisode->id,
            ]);
        }, attempts: 3);
        // OTEL: end transaction span

    }

}
