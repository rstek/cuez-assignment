# Episode Duplication Assignment

Written by Ronald Spilstijns (RSTEK bv) – 12/11/2025  
Proposed Laravel 12 design for duplicating Episodes and their nested Parts → Items → Blocks.

Read the docs online at <https://rstek.github.io/cuez-assignment>.  
If you prefer a local copy, clone the repository and open `index.html` (note: embedded code snippets from `laravel/` will not render in that static view).

## Context
My hands-on Laravel experience was limited to a one-day intro about seven years ago, but given its importance to Cuez I propose this solution in Laravel, leaning on Laracasts and the official documentation.  
Most of my background is in Drupal, Symfony, and plain PHP, so the architectural choices mirror what I would build there: asynchronous queues, background jobs, and aggressive chunking for heavy data work.

Treat the `laravel/` directory as a scratchpad: docblocks were omitted for brevity and most files were never executed—they exist mainly for IntelliSense, scaffolding, and to illustrate the approach.

## Contents
- Assignment brief: [ASSIGNMENT](ASSIGNMENT.md)
- High-level overview: [Overview](Overview.md)
- Data model: [DataModel](DataModel.md)
- Duplication workflow: [Duplication](Duplication.md)
- User feedback plan: [user-feedback](user-feedback.md)
- AWS integration notes: [AWS-Integration](AWS-Integration.md)
- Testing strategy: [Testing](Testing.md)
- Resiliency & recovery: [Resiliency](Resiliency.md)
- Miscellaneous considerations: [Misc](Misc.md)

## Assumptions
- Parts, Items, and Blocks are unique to a given Episode (no cross-episode reuse).
- Episodes contain only `id` and `title`; no authoring metadata is duplicated.
- Duplication succeeds only when the entire hierarchy copies successfully; any failure aborts the run and triggers cleanup.
- Block `media` fields store references to external assets, so duplication copies the reference rather than the file itself.

## Setup
- Episode functionality lives under the default `app` namespace—no modular packages (e.g., `nwidart/laravel-modules`) are introduced for this exercise.
- Queue configuration sets `after_commit = true` so jobs are enqueued only after the surrounding transaction commits.
- Local development can run the database queue driver; higher environments should use AWS SQS or another managed queue.
- Database isolation must be at least `READ COMMITTED` (ideally `REPEATABLE READ` or `SERIALIZABLE`) so only committed data is visible to the jobs.
