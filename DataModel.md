# Data Model

The domain still revolves around the familiar Episode → Part → Item → Block hierarchy,  
but we add an `EpisodeDuplication` aggregate that tracks the end-to-end copy operation and orchestrates the required jobs.

## Entity Relationship Diagram

[](assets/database-schema.mmd ':include :type=code mermaid')

## Initial Migration

[](laravel/database/migrations/2025_11_11_221654_create_episodes_table.php ':include :code php')

## Models

[Episode](laravel/app/Models/Episode.php ':include :code php')

[Part](laravel/app/Models/Part.php ':include :code php')

[Item](laravel/app/Models/Item.php ':include :code php')

[Block](laravel/app/Models/Block.php ':include :code php')

[EpisodeDuplication](laravel/app/Models/EpisodeDuplication.php ':include :code php')

---

**Next:** [Duplication Logic](Duplication.md)