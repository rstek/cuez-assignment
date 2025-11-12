# Testing Strategy

Focus on testing the "Duplication" logic / workflow.
We do not need to "test" if a queue is works like it should.
Focus on our own custom logic.

### Core Functionality Testing
- Episode duplication workflow (see [Duplication Logic](Duplication.md))
  - Are we duplicating the correct records?
  - Does the duplicated episode have the same hierarchy as the original?
- Does a job (chain) fail when it should?

