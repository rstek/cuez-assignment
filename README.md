# Episode Duplication Assignment

Written by Ronald Spilstijns (representing RSTEK bv)  
Date 11/11/2025
Solution for duplicating an Episode and its nested structure (Parts > Items > Blocks) in a Laravel 12 context.  

**Clone repo and open `index.html` in browser to view with docsify.**

## Context
My background is not in Laravel, I knew of it and kept following it.  
Besides a quick 1 day introduction 7 years ago, I haven't created a project with it.    
However, I got the feeling that Laravel is important for Cuez.  
So I took it upon myself to write this solution with laravel.  
With the help of laracast, laravel documentation.

My main experience is with Drupal, symfony, and plain php.
And the solution I would build with Drupal is similar.
Async, queues, jobs, ...

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