# Episode Duplication Assignment

Solution design and support material for duplicating an Episode and its nested structure (Parts > Items > Blocks) in a Laravel 12 context.  
Written by Ronald Spilstijns (representing RSTEK bv)  
Date 11/11/2025

Clone repo and open `index.html` in browser to view with docsify.

## Contents
- Assignment brief: [ASSIGNMENT](./ASSIGNMENT.md)
- Data model: [DataModel](./DataModel.md)
- Proposed duplication approach: [Duplication](./Duplication.md)

## Assumptions

- All Parts, Items, and Blocks are unique per Episode (no reuse across Episodes)
- Episode has only an ID and a Title; no author or additional metadata
- Duplications can only succeed when the whole hierarchy is duplicated; So when a part fails we stop the duplication process and cleanup.

## Setup

- Episode feature would live under the `app` namespace; no modules (e.g., `nwidart/laravel-modules`) for this assignment
- Queue setting `after_commit` is true to avoid enqueuing jobs inside transactions that might roll back
- Local development use "database" driver for queue. Other environments could use AWS SQS or another managed / hosted solution.