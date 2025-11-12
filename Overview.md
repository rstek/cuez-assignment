# High level overview

The solution relies on an asynchronous job chain so duplicating an Episode (and its Parts → Items → Blocks) never blocks the initiating request or overloads the database.  
An `EpisodeDuplication` aggregate owns the workflow, recording status, progress, and metadata needed by each of the four sequential jobs.  
Every job processes input in chunks and bulk-inserts the duplicates, keeping lock times short and throughput high.    
Because the work runs in the background we can surface real-time progress through broadcasts/notifications while still providing graceful failure handling via transaction-level retries.  
Users get an immediate HTTP acknowledgment and can track the duplication from a separate progress view.

## Architecture Diagrams

### 1. Architecture Flow Diagram

[](assets/architecture-flow.mmd ':include :type=code mermaid')

### 2. Job Chain Sequence Diagram

[](assets/job-chain-sequence.mmd ':include :type=code mermaid')

### 3. Database Schema Diagram

[](assets/database-schema.mmd ':include :type=code mermaid')



