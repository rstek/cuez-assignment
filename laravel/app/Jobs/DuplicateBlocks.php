<?php

namespace App\Jobs;

use App\Exceptions\NewEpisodeIdMissing;
use App\Models\Block;
use App\Models\Item;
use App\Models\Part;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateBlocks extends DuplicateBase {

    private const PARTS_CHUNK_SIZE = 10 * self::ITEMS_CHUNK_SIZE; // e.g. 1000

    private const ITEMS_CHUNK_SIZE = 100;                         // e.g. 100

    private const BLOCKS_CHUNK_SIZE = 200;                        // e.g. 200

    private int $newEpisodeId;

    /**
     * Handle the duplication of blocks.
     */
    protected function handleDuplication(): void {
        $this->newEpisodeId = (int) ($this->episodeDuplication->new_episode_id ?? 0);
        if (!$this->newEpisodeId) {
            $this->log('error', 'New episode id not set on duplication');
            throw new NewEpisodeIdMissing($this->duplicationId);
        }

        $this->log('info', 'Duplicating blocks', [
            'parts_chunk_size' => self::PARTS_CHUNK_SIZE,
            'items_chunk_size' => self::ITEMS_CHUNK_SIZE,
            'blocks_chunk_size' => self::BLOCKS_CHUNK_SIZE,
        ]);
        // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'blocks', message => 'blocks duplication started'

        $partsQuery = Part::query()
            ->where('episode_id', $this->newEpisodeId)
            ->whereNotNull('orig_id');

        if (!$partsQuery->exists()) {
            $this->log('info', 'No new parts found for episode; skipping blocks duplication');
            // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'blocks', message => 'No new parts found for episode; skipping blocks'

            return;
        }

        $totalBlocks = 0;
        $processedPartChunks = 0;
        $processedItemChunks = 0;
        $processedBlockChunks = 0;

        // Chunk new parts (outer loop)
        // OTEL: create span for parts query and chunking

        $partsQuery->chunk(self::PARTS_CHUNK_SIZE, function(Collection $parts) use (&$totalBlocks, &$processedPartChunks, &$processedItemChunks, &$processedBlockChunks) {
            /** @var Collection<Part> $parts */
            $newPartIds = $parts->pluck('id')->all();

            $this->log('debug', 'Processing parts chunk for blocks', [
                'parts_chunk_number' => $processedPartChunks,
                'new_part_ids_in_chunk' => count($newPartIds),
            ]);

            if (empty($newPartIds)) {
                $processedPartChunks++;
                return;
            }

            // Chunk new items for these parts (middle loop)
            // OTEL: create span for items query and chunking

            Item::query()
                ->whereIn('part_id', $newPartIds)
                ->whereNotNull('orig_id')
                ->chunk(self::ITEMS_CHUNK_SIZE, function(Collection $items) use (&$totalBlocks, &$processedItemChunks, &$processedBlockChunks) {
                    /** @var Collection<Item> $items */

                    // Build orig_item_id => new_item_id map for this items chunk
                    $origToNewItemMap = $items->pluck('id', 'orig_id')->toArray();
                    $origItemIds = array_keys($origToNewItemMap);

                    $this->log('debug', 'Processing items chunk for blocks', [
                        'items_chunk_number' => $processedItemChunks,
                        'orig_item_ids_in_chunk' => count($origItemIds),
                    ]);

                    if (empty($origItemIds)) {
                        $processedItemChunks++;
                        return;
                    }

                    // Chunk original blocks for these original items (inner loop)
                    // OTEL: create span for blocks query and chunking

                    Block::query()
                        ->whereIn('item_id', $origItemIds)
                        ->chunk(self::BLOCKS_CHUNK_SIZE, function(Collection $blocks) use (&$totalBlocks, &$processedBlockChunks, $origToNewItemMap) {
                            /** @var Collection<Block> $blocks */
                            $inserted = $this->processBlocksChunk($blocks, $processedBlockChunks, $origToNewItemMap);
                            $totalBlocks += $inserted;
                            $processedBlockChunks++;
                        });

                    // OTEL: end blocks query span

                    $processedItemChunks++;
                });
            // OTEL: end items query span

            $processedPartChunks++;
        });
        // OTEL: end parts query span

        $this->log('info', 'Blocks duplication completed', [
            'total_blocks' => $totalBlocks,
            'total_part_chunks' => $processedPartChunks,
            'total_item_chunks' => $processedItemChunks,
            'total_block_chunks' => $processedBlockChunks,
        ]);
        // TODO: dispatch event duplication.completed: id => $this->duplicationId, newEpisodeId => $this->newEpisodeId, completedAt => now()

    }

    /**
     * Process a chunk of blocks.
     *
     * @param Collection<Block> $blocks
     * @param int $chunkNumber
     * @param array $origToNewItemMap
     *
     * @return int Number of blocks inserted
     */
    private function processBlocksChunk(Collection $blocks, int $chunkNumber, array $origToNewItemMap): int {
        // OTEL: create span for processing blocks chunk

        $duplicateBlocks = [];

        foreach ($blocks as $block) {
            $newItemId = $origToNewItemMap[$block->item_id] ?? NULL;
            if (!$newItemId) {
                // TODO: dispatch event duplication.feedback: id => $this->duplicationId, stage => 'blocks', message => 'Missing item mapping; skipping block'
                continue; // No mapping for this block's item; skip
            }

            $newBlock = $block->except(['id', 'item_id']);
            $newBlock['item_id'] = $newItemId;
            $newBlock['orig_id'] = $block->id;
            $duplicateBlocks[] = $newBlock;
        }

        $this->log('debug', 'Processing blocks chunk', [
            'chunk_number' => $chunkNumber,
            'blocks_in_chunk' => count($duplicateBlocks),
        ]);

        $inserted = 0;
        if (!empty($duplicateBlocks)) {
            // OTEL: create span for transaction

            DB::transaction(function() use ($duplicateBlocks, &$inserted) {
                DB::table('blocks')->insert($duplicateBlocks);
                $inserted = count($duplicateBlocks);
                $this->episodeDuplication->addProgress('blocks', $inserted);
                // TODO: dispatch event duplication.progress: id => $this->duplicationId, stage => 'blocks', amount => $inserted
            }, attempts: 3);
        }
        // OTEL: end transaction span

        $this->log('debug', 'Blocks chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'blocks_inserted' => $inserted,
        ]);

        return $inserted;
    }

}
