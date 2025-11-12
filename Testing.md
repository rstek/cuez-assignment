# Testing Strategy

Focus tests on the duplication workflow and our implementation rules, not on Laravel’s queue internals.

### Core Functionality
- Episode duplication happy path (see [Duplication Logic](Duplication.md)); assert hierarchy parity and `orig_id` mapping.
- Job chain failure handling: each stage throws, duplication status flips to failed, no partial records remain visible.

### Large Dataset / Performance
- Use model factories + seeders to generate high-volume episodes (e.g., 50 parts × 100 items × 200 blocks) and ensure chunked jobs finish within acceptable time/memory.
- Stress test concurrent duplications to confirm throttling/middleware behaves and other users aren’t starved.

### Retry & Idempotency
- Simulate job retry (manually dispatch the same job twice or force a failure then `retry`) and ensure no duplicated rows are created thanks to `orig_id` checks.
- Validate rerunning a full duplication after cleanup succeeds and produces a new episode with a distinct ID while preserving mapping data.

### Observability & Events
- Assert expected progress/status events/notifications (broadcasts, logs) are emitted per stage so UI feedback stays accurate.
- Ensure error events include correlation IDs/error codes for support.