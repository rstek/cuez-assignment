# Resiliency & Recovery



## Queue failover
If SQS not available, failover to redis/database driver.
Set in `config/queue.php`
```php
'failover' => [
    'driver' => 'failover',
    'connections' => [
        'sqs',
        'redis',
        'database',
    ],
],
```
We should probably listen to the `QueueFailedOver` event and log + send a notification when it happens.
This failover would be our default connection.

## Restart Mechanisms
Let's assume a job fails. We should be able to resume the duplication process from the last successful DB transaction.
This means we need to be able to track which IDs we have processed and which we have not.
We will assume that already duplicated records should not be checked for any diffs since duplication.
But we need to ensure that new records are also duplicated.




## Monitoring & Alerting

### Health Checks
- Database connectivity and performance
- Queue depth and processing rates
- Memory and CPU utilization
- Job success/failure ratios

### Alert Thresholds
- **Critical**: >10% job failure rate
- **Warning**: Queue depth >1000 jobs
- **Info**: Processing time >5 minutes per job

## Data Integrity

### Transaction Management
- Use database transactions for atomic operations
- Implement savepoints for long-running operations
- Ensure rollback capabilities at each level

### Consistency Checks
```php
class DuplicationConsistencyCheck
{
    public static function validate(int $duplicationId): bool
    {
        $duplication = EpisodeDuplication::find($duplicationId);
        
        $originalCount = Episode::find($duplication->org_episode_id)
            ->parts()->withCount(['items', 'items.blocks'])->get();
            
        $newCount = Episode::find($duplication->new_episode_id)
            ->parts()->withCount(['items', 'items.blocks'])->get();
            
        return $originalCount->count() === $newCount->count();
    }
}
```
