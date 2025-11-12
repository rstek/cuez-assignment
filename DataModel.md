# Data Model

We provide a "Duplication" wrapper called "EpisodeDuplication" to keep track of the duplication process.  
This will enable us to provide meaningful feedback to end users.   
And allows us to orchestrate all the jobs we need to run.

Additionally we have a "ID Map" to keep track of the new IDs for each record.  


## Initial Migration

```php
    public function up(): void
    {
        Schema::create('episodes', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('episode_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained()->onDelete('cascade');
            $table->string('name')->nullable();
            $table->timestamps();
        });

        Schema::create('blocks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_id')->constrained()->onDelete('cascade');
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

        Schema::create('duplication_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplication_id')->constrained('duplications')->onDelete('cascade');
            $table->foreignId('job_id')->constrained('jobs')->setNullOnDelete();
            $table->timestamps();
        });
        
        // The ID map
        Schema::create('id_map', function (Blueprint $table) {
            $table->id();
            $table->foreignId('duplication_id')->constrained('duplications')->onDelete('cascade');
            $table->string('type');
            $table->integer('org_id');
            $table->integer('new_id');
            $table->timestamps();
        });
    }
```