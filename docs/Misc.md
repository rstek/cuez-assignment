# Miscellaneous Notes

## Cache implications
Assuming there is a cache.

After we duplicate an episode, we should "warm up" the cache for the new episode.  
This way we can avoid cache misses when the user tries to access the new episode.  
We could use a job to do this and add it to the end of the job chain.  

## Chunking + Bulk inserts vs Batching
If we would want to use batching for duplicating all parts, items and blocks,  
we would first need to get a complete list of all the records we want to duplicate.  
This could be a problem if the dataset is very large.  

By using chunking and bulk inserts we can avoid this problem.  
You can look at Chunking + Bulk inserts as "streaming data" as we need it.

## Enabled flag or boolean
We should probably include a flag on the models so that parts, items, blocks, episode which are in the process of being created by duplication,  
are not available elsewhere (you would filter them out).

## Use Custom exceptions
When there is a situation that calls for throwing an exception. 
Make sure to create / use a explicit exception.
This will make it easier to track in our observability tools.

## State machine
We should probably have some kind of state machine for the "EpisodeDuplication" model.  
So that we can make explicit transitions and dispatch events when we transition.  
So that in our business logic we do not need to remember where and when to dispatch events.  
