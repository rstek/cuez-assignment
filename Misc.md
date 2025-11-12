# Miscellaneous Notes

## Cache implications
If a cache layer exists, enqueue an additional job at the end of the duplication chain to pre-warm the new episode.  
That keeps the first user request from suffering a cache miss.

## Chunking + Bulk inserts vs Batching
Batch-style duplication would require loading every Part/Item/Block into memory before writing, which explodes for large episodes.  
Chunked bulk inserts stream the data as we go, keeping memory bounded while still minimizing SQL chatter.

## Enabled flag or boolean
Consider an `enabled` (or similar) boolean on each model so records created via duplication stay hidden until the run finishes;  
other features can simply filter for `enabled = true`.

## Use Custom exceptions
Emit domain-specific exceptions instead of generic ones. They make it far easier to route alerts, search logs, and wire structured telemetry.

## State machine
A lightweight state machine on `EpisodeDuplication` would codify allowed transitions and centralize the events emitted when we move between states, reducing business-logic repetition.

## Deadletter queue / failed jobs queue / queue cleanup
Define how failed jobs are triaged—manual replay, automated DLQ consumer, or both—and run periodic cleanup so dead-letter queues do not grow unbounded.
