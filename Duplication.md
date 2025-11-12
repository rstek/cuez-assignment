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
[](laravel/app/Actions/DuplicateEpisodeAction.php ':include :code php')

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

[](laravel/app/Jobs/DuplicateBase.php ':include :code php')

</details>

#### Duplicate Episode (single record)
0. DuplicationBase::handle() will decide if we continue
1. Find episode to duplicate
2. Replicate without ID
3. Save
4. Update EpisodeDuplication with new episode id

<details>
<summary>Duplicate Episode Job</summary>

[](laravel/app/Jobs/DuplicateEpisode.php ':include :code php')
</details>

#### Duplicate Parts
0. DuplicationBase::handle() will decide if we continue
1. Find parts for given episode to duplicate and prepare new records. We chunk the query to limit potential performance issues.
2. Bulk insert duplicate parts for each chunk
3. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Parts Job</summary>

[](laravel/app/Jobs/DuplicateParts.php ':include :code php')
</details>

#### Duplicate Items
0. DuplicationBase::handle() will decide if we continue
1. Given the new episode id, get all new parts (chunked) and list all their original part ids.
2. Given the list of original part ids, find all items for those parts to duplicate and prepare new records. We chunk the query to limit potential performance issues.
3. Bulk insert duplicate items for each chunk 
4. Update EpisodeDuplication with progress

<details>
<summary>Duplicate Items Job</summary>

[](laravel/app/Jobs/DuplicateItems.php ':include :code php')
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
