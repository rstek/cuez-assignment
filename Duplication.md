# Duplication

The duplication process will happen asynchronously. 
Since it can be database-intensive, we do not want to block our end user.

This means when a user initiates a duplication, we will create the necessary records and jobs.  
And return a response to the user immediately and provide feedback to the user in another way.  
Notifications through websockets or server sent events or ... and / or a live refreshing overview of duplications in progress.


## Model

See [DataModel.md](./DataModel.md), the Episode Duplication model will keep track of the duplication process.  
We also associate the "jobs" (laravel) with the actual duplication process.
In our datamodels we keep track of the original ID when a record is created as a duplicate.

## Jobs

For a given episode we will create a chain of duplication jobs

* DuplicateEpisode
* DuplicateParts
* DuplicateItems
* DuplicateBlocks


### Bulk insert
Use bulk insert to reduce amount of queries happening

We would introduce a BaseDuplication job with some basic functionality.  
And then extend it for each level of duplication.
Original episode ID and new episode ID are available in the EpisodeDuplication model.  

#### Base Duplication Job
Provides basic functionality for all duplication jobs.
Checks if the featureflag is enabled.
Will check status of duplication and stop if needed.  
Will handle logging and error handling.  

```php
abstract class DuplicateBase implements ShouldQueue {

    use Queueable;

    protected EpisodeDuplication $episodeDuplication;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $duplicationId,
        protected int $orgEpisodeId
    ) {
        // By not passing the objects, the serialized version of the jobs should be considerably smaller.
    }

    /**
     * Execute the job.
     */
    public function handle(): void {
        $this->episodeDuplication = EpisodeDuplication::find($this->duplicationId);

        // Feature flag gate (pseudo). If disabled, skip the job gracefully.
        if (!$this->featureEnabled()) {
            $this->log('info', 'Duplication feature disabled, skipping job');
            return;
        }


        // Handle status transitions via switch
        switch ($this->episodeDuplication->status) {
            case 'pending':
                $this->episodeDuplication->update(['status' => 'in_progress']);
                $this->log('info', 'Status transitioned from pending to in_progress');
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

        try {
            $this->log('info', 'Starting ' . static::class);
            $this->handleDuplication();
        }
        catch (Throwable $e) {
            $this->log('error', 'Fatal error during duplication', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            $this->episodeDuplication->update(['status' => 'failed']);


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
    protected function featureEnabled(): bool
    {
        return true;
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

#### Duplicate Episode (single record)
0. DuplicationBase::handle() will decide if we continue
1. Find episode to duplicate
2. Replicate without ID
3. Save
4. Update EpisodeDuplication with new episode id
```php
class DuplicateEpisode extends DuplicateBase
{
    /**
     * Handle the duplication of the episode.
     */
    protected function handleDuplication(): void
    {
        $episode = Episode::query()->where('id', $this->orgEpisodeId)->first();

        if (!$episode) {
            $this->log('error', 'Original episode not found', [
                'episode_id' => $this->orgEpisodeId,
            ]);
            throw new \Exception("Episode {$this->orgEpisodeId} not found");
        }

        $this->log('debug', 'Original episode loaded', [
            'episode_title' => $episode->title,
        ]);

        DB::transaction(function () use ($episode) {
            $newEpisode = $episode->replicate(['id']);
            $newEpisode->orig_id = $episode->id;
            $newEpisode->save();

            $this->log('debug', 'New episode created', [
                'new_episode_id' => $newEpisode->id,
                'new_episode_title' => $newEpisode->title,
            ]);

            $this->episodeDuplication->update(['new_episode_id' => $newEpisode->id]);

            $this->log('info', 'Episode duplication completed', [
                'new_episode_id' => $newEpisode->id,
            ]);
        }, attempts: 3);
    }
}
```


#### Duplicate Parts
0. DuplicationBase::handle() will decide if we continue
1. Find parts for given episode to duplicate and prepare new records. We chunk the query to limit potential performance issues.
2. Bulk insert duplicate parts for each chunk 
3. Update EpisodeDuplication with progress

```php
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

        $totalParts = 0;
        $processedChunks = 0;

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
    }

    /**
     * Process a chunk of parts.
     */
    private function processChunk(Collection $parts, int $chunkNumber): void {
        $duplicateParts = [];

        /** @var Collection<int, Part> $parts */
        foreach ($parts as $part) {
            $newPart = $part->except(['id', 'episode_id']);
            $newPart['episode_id'] = $this->newEpisodeId;
            $duplicateParts[] = $newPart;
        }

        $this->log('debug', 'Processing chunk', [
            'chunk_number' => $chunkNumber,
            'parts_in_chunk' => count($duplicateParts),
        ]);

        DB::transaction(function() use ($duplicateParts) {
            DB::table('parts')->insert($duplicateParts);
            $this->episodeDuplication->addProgress('parts', count($duplicateParts));
        }, attempts: 3);

        $this->log('debug', 'Chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'parts_inserted' => count($duplicateParts),
        ]);
    }
}

```


#### Duplicate Items
0. DuplicationBase::handle() will decide if we continue
1. Given the new episode id, get all new parts and list all their original part ids.
2. Given the list of original part ids, find all items for those parts to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate items for each chunk 
4. Update EpisodeDuplication with progress
```php
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

        $partsQuery = Part::query()
            ->where('episode_id', $this->newEpisodeId)
            ->whereNotNull('orig_id');

        if (!$partsQuery->exists()) {
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
            }, attempts: 3);
        }

        $this->log('debug', 'Items chunk processed successfully', [
            'chunk_number' => $chunkNumber,
            'items_inserted' => count($duplicateItems),
        ]);
    }
}
```


#### Duplicate Blocks
0. DuplicationBase::handle() will decide if we continue
1. Given the new episode id, get all new parts 
2. For each new part, get all the new items. And list their original item ids.
2. Given the list of original item ids, find all blocks for those items to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate blocks for each chunk
4. Update EpisodeDuplication with progress



### Alternative naive implementation
This can be useful for just validating an initial idea / as a proof of concept.
<details>
<summary>Naive implementation using replicate </summary>


#### Duplicate Episode

1. Find episode to duplicate
2. Replicate without ID
3. Save
4. Update EpisodeDuplication with new episode id

#### Duplicate Parts

1. Find parts for given episode to duplicate
2. Replicate without ID
3. Save

#### Duplicate Items

1. Find items for given part to duplicate
2. Replicate without ID
3. Save

#### Duplicate Blocks

1. Find blocks for given item to duplicate
2. Replicate without ID
3. Save

</details>




