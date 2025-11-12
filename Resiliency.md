# Resiliency & Recovery

## Restart Mechanisms
If a job fails we resume from the last committed data, which requires tracking which IDs were processed and which still need work.  
 Already-duplicated rows are treated as immutable copies—we simply skip them on retries—while new rows must be cloned.    
To get true idempotency the `EpisodeDuplication` model should store or have access to the processed Part/Item/Block IDs (or ranges).  
 Each job consults that metadata before inserting new records so retries never create duplicates.

In the current described solution on [Duplication](Duplication.md), this isn't present yet.

### Chunk sizes
Chunk sizes should probably be set on the EpisodeDuplication record.
So that when we restart a duplication / continue a failed duplication we can use the same chunk sizes.
Because the constants can change between deployments.

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

## Queue failover

If SQS becomes unavailable the queue should fall back to Redis or the database driver via Laravel’s failover configuration (`config/queue.php`):
This is a possible solution.
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

Listen to the `QueueFailedOver` event so operators get alerted whenever we switch transports; the failover connection becomes the default automatically.

---

**Next:** [User Feedback](user-feedback.md)