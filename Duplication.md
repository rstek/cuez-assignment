# Duplication

We execute duplication asynchronously so the initiating request finishes quickly and the database remains responsive.  
When a user starts the flow we create the tracking records, enqueue the job chain, return immediately, and then keep the user informed via websockets, SSE, or a live duplication status page.


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
[](laravel/app/Actions/DuplicateEpisodeAction.php ':include :code php')

### Job Middleware

Middleware keeps the queue work predictable under load:
```php
abstract class DuplicateBase implements ShouldQueue {
    ...
}

    public function middleware(): array {
        return [
            new ThrottlesExceptions(5, 60), // Allow 5 exceptions per minute
            new WithoutOverlapping("duplication:{$this->duplicationId}"),
            new DatabaseLoadMiddleware()// add middleware that checks RDS load and delays job if too high
        ];
    }

    ...
}
```

#### Database Load Awareness
Example middleware that reads RDS CloudWatch metrics and slows the queue when the database is under pressure:
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

### Bulk insert

Every job extends a `DuplicationBase` job that supplies shared behavior (feature flags, early exits, logging, OTEL hooks).  
Because the Episode and its children are mapped with `orig_id`, each job can build the mapping it needs and perform chunked bulk inserts.  
Each chunk is wrapped in a short transaction, keeping the number of SQL statements predictable even for large hierarchies.

#### Base Duplication Job
Provides basic functionality for all duplication jobs. 
Checks if the featureflag is enabled.  
Will check status of duplication and stop if needed.  
Will handle logging and error handling.  

<details>
<summary>DuplicateBase class</summary>

[](laravel/app/Jobs/DuplicateBase.php ':include :code php')

</details>

#### Duplicate Episode (single record)
0. `DuplicationBase::handle()` decides if we should continue.
1. Find episode to duplicate
2. Replicate without ID
3. Save
4. Update EpisodeDuplication with new episode id

<details>
<summary>Duplicate Episode Job</summary>

[](laravel/app/Jobs/DuplicateEpisode.php ':include :code php')
</details>

#### Duplicate Parts
0. `DuplicationBase::handle()` decides if we should continue.
1. Find parts for given episode to duplicate and prepare new records. We chunk the query to limit potential performance issues.
2. Bulk insert duplicate parts for each chunk
3. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Parts Job</summary>

[](laravel/app/Jobs/DuplicateParts.php ':include :code php')
</details>

#### Duplicate Items
0. `DuplicationBase::handle()` decides if we should continue.
1. Given the new episode id, get all new parts (chunked) and list all their original part ids.
2. Given the list of original part ids, find all items for those parts to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate items for each chunk 
4. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Items Job</summary>

[](laravel/app/Jobs/DuplicateItems.php ':include :code php')
</details>

#### Duplicate Blocks
0. `DuplicationBase::handle()` decides if we should continue.
1. Given the new episode id, get all new parts (chunked)
2. For each new part, get all the new items (chunked) and list their original item ids.
2. Given the list of original item ids, find all blocks for those items to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate blocks for each chunk
4. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Blocks Job</summary>

[](laravel/app/Jobs/DuplicateBlocks.php ':include :code php')
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
