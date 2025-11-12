# Duplication

The duplication process will happen asynchronously. 
Since it can be database-intensive, we do not want to block our end user.

This means when a user initiates a duplication, we will create the necessary records and jobs.  
And return a response to the user immediately and provide feedback to the user in another way.  
Notifications through websockets or server sent events or ... and / or a live refreshing overview of duplications in progress.


## Model

See [DataModel.md](DataModel.md), the Episode Duplication model will keep track of the duplication process.  
For AWS service integration details, see [AWS Integration](AWS-Integration.md).  
For resiliency and cleanup strategies, see [Resiliency & Recovery](Resiliency.md).  
We also associate the "jobs" (laravel) with the actual duplication process.
In our datamodels we keep track of the original ID when a record is created as a duplicate.

## Jobs

For a given episode we will create a chain of duplication jobs

* DuplicateEpisode
* DuplicateParts
* DuplicateItems
* DuplicateBlocks

We encapsulate this behaviour in our "DuplicateEpisodeAction". Which we can later expand with additional logic.  
```php
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

```

### Job Middleware

To ensure robust and controlled execution, we'll implement several job middleware:
```php
abstract class DuplicateBase implements ShouldQueue {
    ...
}

    public function middleware(): array {
        return [
            new ThrottlesExceptions(5, 60), // Allow 5 exceptions per minute
            new WithoutOverlapping("duplication:{$this->duplicationId}"),
            // add middleware that checks RDS load and delays job if too high
        ];
    }

    ...
}
```

#### Database Load Awareness
Potential Monitor RDS CloudWatch metrics and adjust queue processing:
```php
class DatabaseLoadMiddleware
{
    public function handle($job, $next)
    {
        $cpuUtilization = $this->getRdsCpuUtilization();
        
        if ($cpuUtilization > 80) {
            $job->release(60); // Delay for 1 minute
            return;
        }
        
        return $next($job);
    }
}
```

#### Concurrency Control
Limit concurrent duplication jobs to prevent database overload:
```php
// In queue configuration
'duplication' => [
    'driver' => 'sqs',
    'connection' => 'default',
    'queue' => 'duplication',
    'after_commit' => true,
    'max_jobs' => 5, // Limit concurrent jobs
    'memory' => 256,
]
```

### Bulk insert
Use bulk insert to reduce amount of queries happening

We would introduce a BaseDuplication job with some basic functionality.  
And then extend it for each level of duplication.
Original episode ID and new episode ID are available in the EpisodeDuplication model.

Given that we chunk our queries, we can use a single transaction for each chunk.
And if the amount of objects is smaller than our chunk sizes,   
we will limit the amount of insert / update queries to a single query for each level of the hierarchy.

#### Base Duplication Job
Provides basic functionality for all duplication jobs.
Checks if the featureflag is enabled.
Will check status of duplication and stop if needed.  
Will handle logging and error handling.  

<details>
<summary>DuplicateBase class</summary>

```php
<?php

namespace App\Jobs;

use App\Models\EpisodeDuplication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class DuplicateBase implements ShouldQueue {

    use Queueable;

    protected EpisodeDuplication $episodeDuplication;

    // OTEL: provide spanInterface $jobSpan & TracerInterface

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $duplicationId,
        protected int $orgEpisodeId
    ) {
        // By not passing the objects, the serialized version of the jobs should be considerably smaller.
    }

    public function middleware(): array {
        return [
            new ThrottlesExceptions(5, 60), // Allow 5 exceptions per minute
            new WithoutOverlapping("duplication:{$this->duplicationId}"),
            // add middleware that checks RDS load and delays job if too high
        ];
    }

    /**
     * Execute the job.
     */
    public function handle(): void {
        // OTEL: Create & activate job span for current job with attributes like
        // "job_class" (static::class), "duplication_id", "org_episode_id", "started_at"

        $this->episodeDuplication = EpisodeDuplication::find($this->duplicationId);

        // Feature flag gate (pseudo). If disabled, skip the job gracefully.
        if (!$this->featureEnabled()) {
            $this->log('info', 'Duplication feature disabled, skipping job');
            $this->release(30); // Release back into queue after 30 seconds. Assuming the feature will be re-enabled
            // at some point.
            return;
        }

        // Handle status transitions via switch
        // OTEL: create span for status transition if needed
        switch ($this->episodeDuplication->status) {
            case 'pending':
                $this->episodeDuplication->update(['status' => 'in_progress']);
                $this->log('info', 'Status transitioned from pending to in_progress');
                // TODO: dispatch event duplication.started: id => $this->duplicationId, startedAt => now()
                break;

            case 'in_progress':
                // continue
                break;
            default:
                $this->log('info', 'Duplication job stopped', [
                    'current_status' => $this->episodeDuplication->status,
                ]);
                return;
        }
        // OTEL: close status transition span

        try {
            $this->log('info', 'Starting ' . static::class);
            $this->handleDuplication();
            // OTEL: set status job span
        }
        catch (Throwable $e) {
            // OTEL: record exception in job span and set status to error
            $this->episodeDuplication->update(['status' => 'failed']);
            $this->log('error', 'Fatal error during duplication', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'exception_class' => get_class($e),
            ]);
            // TODO: dispatch event duplication.failed: id => $this->duplicationId, errorCode => get_class($e), message => $e->getMessage()
            throw $e;
        }
    }

    /**
     * Handle the duplication logic. Implement in child classes.
     */
    abstract protected function handleDuplication(): void;

    /**
     * Pseudo feature flag toggle. Return false to disable duplication jobs.
     */
    protected function featureEnabled(): bool {
        return TRUE;
    }

    /**
     * Log a message with context.
     */
    protected function log(string $level, string $message, array $context = []): void {
        $context = array_merge([
            'duplication_id' => $this->duplicationId,
            'org_episode_id' => $this->orgEpisodeId,
            'job_class' => static::class,
        ], $context);

        Log::channel("duplication")->$level($message, $context);
    }

}

```
</details>

#### Duplicate Episode (single record)
0. DuplicationBase::handle() will decide if we continue
1. Find episode to duplicate
2. Replicate without ID
3. Save
4. Update EpisodeDuplication with new episode id

<details>
<summary>Duplicate Episode Job</summary>

```php
<?php

namespace App\Jobs;

use App\Exceptions\OriginalEpisodeNotFound;use App\Models\Episode;use Illuminate\Support\Facades\DB;

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

```
</details>

#### Duplicate Parts
0. DuplicationBase::handle() will decide if we continue
1. Find parts for given episode to duplicate and prepare new records. We chunk the query to limit potential performance issues.
2. Bulk insert duplicate parts for each chunk
3. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Parts Job</summary>

```php
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

```
</details>

#### Duplicate Items
0. DuplicationBase::handle() will decide if we continue
1. Given the new episode id, get all new parts (chunked) and list all their original part ids.
2. Given the list of original part ids, find all items for those parts to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate items for each chunk 
4. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Items Job</summary>

```php
<?php

namespace App\Jobs;

use App\Exceptions\NewEpisodeIdMissing;
use App\Models\Item;
use App\Models\Part;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DuplicateItems extends DuplicateBase {

    private const PARTS_CHUNK_SIZE = 10 * self::ITEMS_CHUNK_SIZE;

    private const ITEMS_CHUNK_SIZE = 100;

    private int $newEpisodeId;

    /**
     * Handle the duplication of items.
     */
    protected function handleDuplication(): void {
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

        // OTEL: create span for parts query and chunking

        $partsQuery->chunk(self::PARTS_CHUNK_SIZE, function(Collection $parts) use (
            &$totalItems, &$processedPartChunks,
            &$processedItemChunks
        ) {
            /** @var Collection<Part> $parts */

            // Create mapping of original part ID to new part ID
            $origToNewPartMap = $parts->pluck('id', 'orig_id')->toArray();
            $origPartIds = array_keys($origToNewPartMap);

            $this->log('debug', 'Processing parts chunk for items', [
                'parts_chunk_number' => $processedPartChunks,
                'orig_ids_in_chunk' => count($origPartIds),
            ]);

            // OTEL: create span for items query and chunking

            Item::query()
                ->whereIn('part_id', $origPartIds)
                ->chunk(self::ITEMS_CHUNK_SIZE, function(Collection $items) use (
                    &$totalItems, &$processedItemChunks,
                    $origToNewPartMap
                ) {
                    /** @var Collection<Item> $items */

                    $this->processChunk($items, $processedItemChunks, $origToNewPartMap);
                    $totalItems += $items->count();
                    $processedItemChunks++;
                });
            // OTEL: end items query span

            $processedPartChunks++;
        });
        // OTEL: end parts query span

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
    private function processChunk(Collection $items, int $chunkNumber, array $origToNewPartMap): void {
        // OTEL: create span for processing items chunk
        $duplicateItems = [];

        foreach ($items as $item) {
            $newPartId = $origToNewPartMap[$item->part_id] ?? NULL;
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

        // OTEL: create span for transaction

        if (!empty($duplicateItems)) {
            DB::transaction(function() use ($duplicateItems) {
                DB::table('items')->insert($duplicateItems);
                $this->episodeDuplication->addProgress('items', count($duplicateItems));
                // TODO: dispatch event duplication.progress: id => $this->duplicationId, stage => 'items', amount => count($duplicateItems)
            }, attempts: 3);
        }
        // OTEL: end transaction span

        $this->log('debug', 'Items chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'items_inserted' => count($duplicateItems),
        ]);
    }

}

```
</details>

#### Duplicate Blocks
0. DuplicationBase::handle() will decide if we continue
1. Given the new episode id, get all new parts (chunked)
2. For each new part, get all the new items (chunked). And list their original item ids.
2. Given the list of original item ids, find all blocks for those items to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate blocks for each chunk
4. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Blocks Job</summary>

```php
<?php

namespace App\Jobs;

use App\Exceptions\NewEpisodeIdMissing;use App\Models\Block;use App\Models\Item;use App\Models\Part;use Illuminate\Support\Collection;use Illuminate\Support\Facades\DB;

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

```
</details>

### Alternative naive implementation
This can be useful for just validating an initial idea / as a proof of concept.
<details>
<summary>Naive implementation using replicate </summary>

Single Job that duplicates all records.  
Iterate through the hierarchy and replicate each record.  
1. Replicate episode
2. for each part of episode, replicate part and set episode_id to new episode.
3. for each original part, get the original items, and for each item, replicate item and set part_id to new part.
4. For each original item, get the original blocks, and for each block, replicate block and set item_id to new item.

So for a single episode with 10 parts, with each 5 items and each 20 blocks.
This would be at least 10 * 5 * 20 = 1000 insert/update queries.
And probably one big DB transaction.

</details>
