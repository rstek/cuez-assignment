# Transaction structure

### ℹ️ **Context & info**

---

The main business unit of our application is the concept of “Episode”. An episode is composed by a nested structure of:

- Episode
    - Parts
        - Items
            - Blocks
                - Block Fields
                - Media

As a User, I am not imposed any limits on the number of children of each of these elements, all of them have a One-to-Many relation between a child level and its parent (one Episode can have zero or many Parts, one Part can have zero or many Items, and so on…), which makes Episodes potentially infinite in size.

### 💡Assignment

---

Naturally there are some actions we allow our users such as Duplicating an Episode, which include duplicating all the instances and models inside. Focusing in this specific flow, explain how you would ideally approach its implementation.

Please refer to any coding (preferably Laravel) patterns/tools or AWS services that could help you define your solution. Use text code/pseudo-code snippets, diagrams, or whatever other artifacts that can help you explain the solution.

We’ll be paying special attention to:

- Interaction with the database and its efficiency
    - namely transaction usage and control during the process
- How well the solution is explained, and its support material
- Scalability and observability of the solution
- How the solution would potentially affect other users in the platform during the process
- Solution resiliency and failure reaction

Please let us know if there’s any questions we can help clarify, and good luck!