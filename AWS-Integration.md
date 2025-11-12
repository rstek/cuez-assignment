# AWS Integration

This document outlines the AWS services that would/could be used to support the episode duplication system in a production environment.

## Note

I haven't used the AWS ecosystem much.   
So based on documentation online I would use the following services.

## Services
We would use the following services.

![AWS.drawio.png](assets/AWS.drawio.png)

### Database (RDS PostgreSQL)
Amazon RDS for our relational database.  
We could then use the CloudWatch metrics to monitor the database load and adjust the queue processing accordingly.  
By creating a custom middleware, we can check the RDS CPU utilization and delay the job if it's too high.

### Queue Management (SQS)
Based on documentation we should probably use the SQS FIFO queue.  
A "episode duplication" FIFO queue.

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
Use amazon S3 for storing media files.  
We could use the S3 SDK to generate pre-signed URLs for accessing the media files.  
This way we can avoid exposing the S3 bucket to the public.  
And we can control the access to the media files.  
A media reference would be a canonical object key not a pre-signed URL.  

### Monitoring & Logging (CloudWatch)

### Key Metrics
- Job processing rates and success/failure ratios
- Database load
- Queue depth and processing latency
- Application response times

### Alerting
- Critical: Job failure rates above threshold
- Warning: Queue depth growing unusually
- Info: System performance metrics

### Logging
- Structured JSON logs for easy parsing
- Correlation IDs for request tracing
- Using our custom exceptions for tracking

### Open telemetry
- Use OTEL Traces & metrics to provide additional information.
- I've added comments in code with prefix "OTEL: " where we would add traces/spans
- Omitted adding events to spans, etc.
