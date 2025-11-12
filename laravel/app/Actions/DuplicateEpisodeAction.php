<?php

namespace App\Actions;

use App\Jobs\DuplicateBlocks;
use App\Jobs\DuplicateEpisode;
use App\Jobs\DuplicateItems;
use App\Jobs\DuplicateParts;
use App\Models\Episode;
use App\Models\EpisodeDuplication;
use Illuminate\Support\Facades\Bus;
use Throwable;

class DuplicateEpisodeAction {

    public function __invoke(Episode $episode): void {
        // OTEL: create Root Span - Attributes: episode_id, duplication_id
        // OTEL: activate span
        $episodeDuplication = EpisodeDuplication::createForEpisode($episode);

        // OTEL: create child span for job dispatch & start
        Bus::chain([
            new DuplicateEpisode($episodeDuplication->id, $episodeDuplication->episode_id),
            new DuplicateParts($episodeDuplication->id, $episodeDuplication->episode_id),
            new DuplicateItems($episodeDuplication->id, $episodeDuplication->episode_id),
            new DuplicateBlocks($episodeDuplication->id, $episodeDuplication->episode_id),
        ])->catch(function(Throwable $e) use ($episodeDuplication) {
            // Handle the exception
            $episodeDuplication->failed($e);
        })->onConnection('sqs')->onQueue('duplicate_episodes')->dispatch();
    }

}
