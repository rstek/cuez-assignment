# AWS Integration

## Overview

This document outlines the AWS services that would/could be used to support the episode duplication system in a production environment.


## Services

### Database (RDS PostgreSQL)
Amazon RDS for our relational database.
We could then use the CloudWatch metrics to monitor the database load and adjust the queue processing accordingly.
By creating a custom middleware, we can check the RDS CPU utilization and delay the job if it's too high.

### Queue Management (SQS)
Based on documentation we should probably use the SQS FIFO queue.  
Which means we need to provide a "message group ID". To determine which jobs can be processed in parallel. (Same id, no parallelism. Different id, parallelism.)  
Additionally we could look into the "message deduplication" feature of SQS FIFO queues.
Then we should provide a deduplicationId method on our jobs
```php
    /**
     * Get the job's deduplication ID.
     */
    public function deduplicationId(): string
    {
        return "duplication-{$this->$duplicationId}";
    }
```

### File Storage (S3)


### Monitoring & Logging (CloudWatch)




## Monitoring Strategy

### Key Metrics
- Job processing rates and success/failure ratios
- Database connection pool utilization
- Queue depth and processing latency
- Application response times

### Alerting
- Critical: Job failure rates above threshold
- Warning: Queue depth growing unusually
- Info: System performance metrics

### Logging
- Structured JSON logs for easy parsing
- Correlation IDs for request tracing
- Log retention policies based on compliance requirements