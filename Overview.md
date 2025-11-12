# High level overview


## Architecture Diagrams

### 1. Architecture Flow Diagram

```mermaid
flowchart TD
    A[User Request] --> B[Laravel Route]
    B --> C[Create Duplication Record]
    C --> D[Queue Job Chain]
    C --> E[Return Response]

    D --> F[DuplicateEpisode]
    F --> G[DuplicateParts]
    G --> H[DuplicateItems]
    H --> I[DuplicateBlocks]
    I --> J[Mark Complete]

    E --> K[User Tracks Progress]
    J --> K

    style A fill:#e1f5fe
    style E fill:#c8e6c9
    style J fill:#c8e6c9
```

### 2. Job Chain Sequence Diagram

```mermaid
sequenceDiagram
    participant User
    participant Route
    participant Queue
    participant Jobs
    
    User->>Route: Duplicate Episode
    Route->>Queue: Dispatch Job Chain
    Route-->>User: Accepted
    
    Queue->>Jobs: DuplicateEpisode
    Jobs->>Queue: Next: DuplicateParts
    
    Queue->>Jobs: DuplicateParts
    Jobs->>Queue: Next: DuplicateItems
    
    Queue->>Jobs: DuplicateItems
    Jobs->>Queue: Next: DuplicateBlocks
    
    Queue->>Jobs: DuplicateBlocks
    Jobs-->>Queue: Complete
    
    Note over User,Jobs: User tracks progress throughout
```

### 3. Database Schema Diagram

```mermaid
erDiagram
    EPISODES {
        int id PK
        string title
    }
    
    PARTS {
        int id PK
        int episode_id FK
        int orig_id FK
    }
    
    ITEMS {
        int id PK
        int part_id FK
        int orig_id FK
    }
    
    BLOCKS {
        int id PK
        int item_id FK
        int orig_id FK
    }
    
    DUPLICATIONS {
        int id PK
        int episode_id FK
        int new_episode_id FK
        string status
    }
    
    
    
    EPISODES ||--o{ PARTS : "has many"
    PARTS ||--o{ ITEMS : "has many"
    ITEMS ||--o{ BLOCKS : "has many"
    EPISODES ||--o{ DUPLICATIONS : "source/target"
    
    PARTS ||--o{ PARTS : "original record"
    ITEMS ||--o{ ITEMS : "original record"
    BLOCKS ||--o{ BLOCKS : "original record"
    
    
```




