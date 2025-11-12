<?php

namespace App\Jobs;

use App\Models\Part;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateParts extends DuplicateBase {

    private const CHUNK_SIZE = 100;

    private $newEpisodeId;

    /**
     * Handle the duplication of parts.
     */
    protected function handleDuplication(): void {
        $this->newEpisodeId = $this->episodeDuplication->getNewEpisodeId();

        $this->log('info', 'Duplicating parts', [
            'chunk_size' => self::CHUNK_SIZE,
        ]);
        // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'parts', message => 'parts duplication started'

        $totalParts = 0;
        $processedChunks = 0;
        // OTEL: create span for database query span
        Part::query()
            ->where('episode_id', $this->orgEpisodeId)
            ->chunk(self::CHUNK_SIZE, function(Collection $parts) use (&$totalParts, &$processedChunks) {
                $this->processChunk($parts, $processedChunks);
                $totalParts += $parts->count();
                $processedChunks++;
            });

        $this->log('info', 'Parts duplication completed', [
            'total_parts' => $totalParts,
            'total_chunks' => $processedChunks,
        ]);
        // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'parts', message => 'parts finished duplicating', total_parts => $totalParts

        // OTEL: end database query span
    }

    /**
     * Process a chunk of parts.
     */
    private function processChunk(Collection $parts, int $chunkNumber): void {
        // OTEL: create span for processing Chunk

        $duplicateParts = [];

        /** @var Collection<Part> $parts */
        foreach ($parts as $part) {
            $newPart = $part->except(['id', 'episode_id']);
            $newPart['episode_id'] = $this->newEpisodeId;
            $newPart['orig_id'] = $part->id;
            $duplicateParts[] = $newPart;
        }

        $this->log('debug', 'Processing chunk', [
            'chunk_number' => $chunkNumber,
            'parts_in_chunk' => count($duplicateParts),
        ]);

        // OTEL: create span for transaction
        DB::transaction(function() use ($duplicateParts) {
            // TODO: dispatch event duplication.progress: id => $this->duplicationId, stage => 'parts', amount => count($duplicateParts)

            DB::table('parts')->insert($duplicateParts);
            $this->episodeDuplication->addProgress('parts', count($duplicateParts));
        }, attempts: 3);
        // OTEL: end transaction span

        $this->log('debug', 'Chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'parts_inserted' => count($duplicateParts),
        ]);
    }

}
