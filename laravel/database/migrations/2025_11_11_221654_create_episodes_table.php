<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * Run the migrations.
     */
    public function up(): void {
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

    /**
     * Reverse the migrations.
     */
    public function down(): void {
        Schema::dropIfExists('duplications');
        Schema::dropIfExists('blocks');
        Schema::dropIfExists('items');
        Schema::dropIfExists('parts');
        Schema::dropIfExists('episodes');
    }

};
