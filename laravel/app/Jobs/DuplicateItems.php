<?php

namespace App\Jobs;

use App\Models\Item;
use App\Models\Part;

use App\Models\EpisodeDuplication;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;
use App\Exceptions\NewEpisodeIdMissing;

class DuplicateItems extends DuplicateBase
{
    private const PARTS_CHUNK_SIZE = 10 * self::ITEMS_CHUNK_SIZE;
    private const ITEMS_CHUNK_SIZE = 100;

    private int $newEpisodeId;

    /**
     * Handle the duplication of items.
     */
    protected function handleDuplication(): void
    {
        $this->newEpisodeId = (int) ($this->episodeDuplication->new_episode_id ?? 0);

        if (!$this->newEpisodeId) {
            $this->log('error', 'New episode id not set on duplication');
            throw new NewEpisodeIdMissing($this->duplicationId);
        }

        $this->log('info', 'Duplicating items', [
            'parts_chunk_size' => self::PARTS_CHUNK_SIZE,
            'items_chunk_size' => self::ITEMS_CHUNK_SIZE,
        ]);
        // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'items', message => 'items duplication started'


        $partsQuery = Part::query()
            ->where('episode_id', $this->newEpisodeId)
            ->whereNotNull('orig_id');

        if (!$partsQuery->exists()) {
            // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'items', message => 'No new parts found for episode; skipping items'

            $this->log('info', 'No new parts found for episode; skipping items duplication');
            return;
        }

        $totalItems = 0;
        $processedPartChunks = 0;
        $processedItemChunks = 0;

        $partsQuery->chunk(self::PARTS_CHUNK_SIZE, function (Collection $parts) use (&$totalItems, &$processedPartChunks,
            &$processedItemChunks) {
            /** @var Collection<Part> $parts */

            // Create mapping of original part ID to new part ID
            $origToNewPartMap = $parts->pluck('id', 'orig_id')->toArray();
            $origPartIds = array_keys($origToNewPartMap);

            $this->log('debug', 'Processing parts chunk for items', [
                'parts_chunk_number' => $processedPartChunks,
                'orig_ids_in_chunk' => count($origPartIds),
            ]);

            Item::query()
                ->whereIn('part_id', $origPartIds)
                ->chunk(self::ITEMS_CHUNK_SIZE, function (Collection $items) use (&$totalItems, &$processedItemChunks,
                    $origToNewPartMap) {
                    /** @var Collection<Item> $items */

                    $this->processChunk($items, $processedItemChunks, $origToNewPartMap);
                    $totalItems += $items->count();
                    $processedItemChunks++;
                });

            $processedPartChunks++;
        });

        $this->log('info', 'Items duplication completed', [
            'total_items' => $totalItems,
            'total_part_chunks' => $processedPartChunks,
            'total_item_chunks' => $processedItemChunks,
        ]);
        // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'items', message => 'items finished duplicating', total_items => $totalItems

    }

    /**
     * Process a chunk of items.
     *
     * @param Collection<Item> $items
     */
    private function processChunk(Collection $items, int $chunkNumber, array $origToNewPartMap): void
    {
        $duplicateItems = [];



        foreach ($items as $item) {
            $newPartId = $origToNewPartMap[$item->part_id] ?? null;
            if (!$newPartId) {
                // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'items', message => 'Missing part mapping; skipping item'
                continue;
            }

            $newItem = $item->except(['id', 'part_id']);
            $newItem['part_id'] = $newPartId;
            $newItem['orig_id'] = $item->id;
            $duplicateItems[] = $newItem;
        }

        $this->log('debug', 'Processing items chunk', [
            'chunk_number' => $chunkNumber,
            'items_in_chunk' => count($duplicateItems),
        ]);

        if (!empty($duplicateItems)) {
            DB::transaction(function () use ($duplicateItems) {
                DB::table('items')->insert($duplicateItems);
                $this->episodeDuplication->addProgress('items', count($duplicateItems));
                // TODO: dispatch event duplication.progress: id => $this->duplicationId, stage => 'items', amount => count($duplicateItems)
            }, attempts: 3);
        }

        $this->log('debug', 'Items chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'items_inserted' => count($duplicateItems),
        ]);
    }
}
