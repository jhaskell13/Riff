<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('artists', function (Blueprint $table) {
            $table->id(); // Primary key
            $table->string('name')->index(); // Artist/band name
            $table->string('location')->nullable()->index(); // City, country, etc.
            $table->string('genre')->nullable()->index(); // Primary genre
            $table->json('genres')->nullable(); // Multiple genres as JSON array
            $table->json('members')->nullable(); // Band members as JSON array
            $table->text('biography')->nullable(); // Artist bio/description
            $table->string('website')->nullable(); // Official website
            $table->string('email')->nullable(); // Contact email
            $table->json('social_media')->nullable(); // Social media links as JSON
            $table->string('image_url')->nullable(); // Profile/band photo URL
            $table->date('formed_date')->nullable(); // When band was formed
            // $table->date('disbanded_date')->nullable(); // If band broke up
            // $table->boolean('is_active')->default(true); // Current status
            // $table->enum('type', ['solo', 'band', 'duo', 'group'])->default('band'); // Artist type
            // $table->string('record_label')->nullable(); // Current record label
            // $table->string('manager_contact')->nullable(); // Manager contact info
            // $table->decimal('popularity_score', 5, 2)->nullable(); // Popularity ranking
            // $table->string('spotify_id')->nullable()->unique(); // Spotify artist ID
            // $table->string('apple_music_id')->nullable()->unique(); // Apple Music ID
            $table->timestamps(); // created_at, updated_at
            $table->softDeletes(); // deleted_at for soft deletes

            // Indexes for better query performance
            // $table->index(['is_active', 'type']);
            // $table->index('formed_date');
            // $table->index('popularity_score');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artists');
    }
};
