# Data Model

We have the expected "Episode", "Part", "Item", "Block" models.

But we also provide a "Duplication" wrapper called "EpisodeDuplication" to keep track of the duplication process.  
And allows us to orchestrate all the jobs we need to run.


## Initial Migration

```php
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('orig_id')->nullable()->index();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->onDelete('cascade');
            $table->foreignId('orig_id')->nullable()->constrained('parts')->onDelete('set null');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->onDelete('cascade');
            $table->foreignId('orig_id')->nullable()->constrained('items')->onDelete('set null');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
            $table->foreignId('orig_id')->nullable()->constrained('blocks')->onDelete('set null');
            $table->string('name')->nullable();
            $table->string('field_1')->nullable();
            $table->string('field_2')->nullable();
            $table->string('field_3')->nullable();
            $table->string('media')->nullable(); // references a file in the storage
            $table->timestamps();
        });

        Schema::create('duplications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->onDelete('cascade');
            $table->foreignId('new_episode_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('pending'); // pending, in_progress, failed, completed
            $table->json('progress')->default('{}');
            $table->timestamps();
        });
    }
```