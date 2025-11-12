# Episode Duplication Assignment

Written by Ronald Spilstijns (representing RSTEK bv)  
Date 12/11/2025
Solution for duplicating an Episode and its nested structure (Parts > Items > Blocks) in a Laravel 12 context.  

**Clone repo and open `index.html` in browser to view with docsify.**
Or you can view it in github https://rstek.github.io/cuez-assignment

## TODO for Ronald

* Cleanup failed duplications?
* Partial completes? What if it fails half way, can we recover?
* Failed jobs? (dead letter queue)

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
- Assignment brief: [ASSIGNMENT](ASSIGNMENT.md)
- High level overview: [Overview](Overview.md)
- Data model: [DataModel](DataModel.md)
- Proposed duplication approach: [Duplication](Duplication.md)
- User feedback: [User Feedback](user-feedback.md)
- AWS Integration: [AWS Integration](AWS-Integration.md)
- Testing Strategy: [Testing](Testing.md)
- Resiliency & Recovery: [Resiliency](Resiliency.md)
- Miscellaneous: [Misc](Misc.md)
- The laravel sub folder should be considered my "scratch" pad for this assignment. 
  - docblocks were omitted for brevity.
  - Most of the code has never been ran. Basically used it for intellisense / autocomplete / artisan commands.

## Assumptions

- All Parts, Items, and Blocks are unique per Episode (no reuse across Episodes)
- Episode has only an ID and a Title; no author or additional metadata
- Duplications can only succeed when the whole hierarchy is duplicated; So when a part fails we stop the duplication process and cleanup.
- "Media" for blocks is an external reference for which we will copy the reference

## Setup

- Episode feature would live under the `app` namespace; no modules (e.g., `nwidart/laravel-modules`) for this assignment
- Queue setting `after_commit` is true to avoid enqueuing jobs inside transactions that might roll back (just in case)
- Local development use "database" driver for queue. Other environments could use AWS SQS or another managed / hosted solution.
- DB transaction isolation level should not be "READ UNCOMMITTED". Only actually committed data should be visible. 
  - Allowed options: READ COMMITTED, REPEATABLE READ, SERIALIZABLE