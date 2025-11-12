# Resiliency & Recovery

## Restart Mechanisms

Let's assume a job fails. We should be able to resume the duplication process from the last successful DB transaction.
This means we need to be able to track which IDs we have processed and which we have not.
We will assume that already duplicated records should not be checked for any diffs since duplication.
But we need to ensure that new records are also duplicated.

In my originally written solution I forgot to add a mechanism that would provide "repeatability" idempotency.  
Meaning that if a job fails and is retried, it should not duplicate the records again.

In order to provide this, we would need to update our EpisodeDuplication model to keep track of all the IDs of parts,
items and blocks that have been duplicated.  
And then in our duplication jobs we would check if the IDs have already been processed.  
If so, we would skip them.  
If not, we would duplicate them.

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

Potential failover to other queue drivers.

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
