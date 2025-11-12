# Data Model

We have the expected "Episode", "Part", "Item", "Block" models.

But we also provide a "Duplication" wrapper called "EpisodeDuplication" to keep track of the duplication process.  
And allows us to orchestrate all the jobs we need to run.

## Initial Migration

[](laravel/database/migrations/2025_11_11_221654_create_episodes_table.php ':include :code php')

## Models

[Episode](laravel/app/Models/Episode.php ':include :code php')
[Part](laravel/app/Models/Part.php ':include :code php')
[Item](laravel/app/Models/Item.php ':include :code php')
[Block](laravel/app/Models/Block.php ':include :code php')
[EpisodeDuplication](laravel/app/Models/EpisodeDuplication.php ':include :code php')