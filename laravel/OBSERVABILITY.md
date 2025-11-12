# Episode Duplication - Observability & Logging

## Overview

The duplication jobs now include comprehensive logging and observability features with environment-aware behavior (production vs non-production).

## Logging Channels

### Production Environment
- **`duplication`** - Main production logs
  - Path: `storage/logs/duplication.log`
  - Level: `info` (only important events)
  - Retention: 30 days
  - No stack traces (security)

### Non-Production Environment
- **`duplication_debug`** - Detailed debug logs
  - Path: `storage/logs/duplication-debug.log`
  - Level: `debug` (all events)
  - Retention: 7 days
  - Includes full stack traces

## Logging Methods

All duplication jobs extend `DuplicateBase` which provides:

```php
// Log at different levels
$this->debug($message, $context);      // Only in non-prod
$this->info($message, $context);       // Always
$this->warning($message, $context);    // Always
$this->error($message, $context);      // Always
```

## Context Data

All logs automatically include:
- `duplication_id` - The duplication record ID
- `org_episode_id` - Original episode ID
- `new_episode_id` - New episode ID
- `job_class` - The job class name

## DuplicateParts Job - Logging Events

### Start
```
Starting duplication of parts
- chunk_size: 100
```

### Per Chunk (Debug - Non-Prod Only)
```
Processing chunk
- chunk_number: 0
- parts_in_chunk: 100

Chunk processed successfully
- chunk_number: 0
- parts_inserted: 100
```

### Chunk Error
```
Error processing chunk
- chunk_number: 0
- parts_in_chunk: 100
- exception: [error message]
```

### Completion
```
Parts duplication completed successfully
- total_parts: 5000
- total_chunks: 50
```

### Fatal Error
```
Fatal error during parts duplication
- exception: [error message]
- trace: [full trace in non-prod only]
```

## Error Handling

When an error occurs:
- Job fails immediately
- Duplication status is set to `failed`
- Error is logged with full context (exception message, stack trace in non-prod)
- Exception is re-thrown to the queue system for retry handling

Errors are logged but not persisted in the model - they're available in the log files for investigation.

## Configuration

Add to `.env`:

```env
# Log retention (days)
LOG_DUPLICATION_DAYS=30
LOG_DUPLICATION_DEBUG_DAYS=7

# Log level (debug, info, notice, warning, error, critical, alert, emergency)
LOG_LEVEL=info
```

## Monitoring Tips

### Production
Monitor `storage/logs/duplication.log` for:
- Failed duplications
- Warnings about partial failures
- Completion summaries

### Development
Monitor `storage/logs/duplication-debug.log` for:
- Chunk processing details
- Performance metrics
- Full error traces

### Real-time Monitoring
```bash
# Watch production logs
tail -f storage/logs/duplication.log

# Watch debug logs
tail -f storage/logs/duplication-debug.log

# Filter by duplication ID
grep "duplication_id\":123" storage/logs/duplication.log
```

## Future Enhancements

- Add metrics/events for monitoring systems (DataDog, New Relic, etc.)
- Add progress webhooks for real-time UI updates
- Add performance metrics (duration, throughput)
- Add structured logging with JSON format

