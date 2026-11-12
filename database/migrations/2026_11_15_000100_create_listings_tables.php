<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Listings ("looking for"), their genres, and the replies relayed to
     * their posters. Vocabulary columns are strings validated in code.
     */
    public function up(): void
    {
        Schema::create('listings', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Posted as this artist (required for some types, never for
            // others; see config riff.listings.types).
            $table->foreignId('artist_id')->nullable()->constrained()->nullOnDelete();
            $table->string('title', 120);
            $table->text('body');
            $table->json('instruments')->nullable();
            // Town-level only, like everything personal on Riff.
            $table->string('city');
            $table->string('state');
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['expires_at', 'lat', 'lng']);
            $table->index('artist_id');
        });

        Schema::create('listing_genre', function (Blueprint $table) {
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('genre_id')->constrained()->cascadeOnDelete();
            $table->primary(['listing_id', 'genre_id']);
            $table->index(['genre_id', 'listing_id']);
        });

        Schema::create('listing_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('listing_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('message');
            // What the replier chose to share — the only way back to them
            // until in-app messaging exists.
            $table->string('contact', 200);
            $table->timestamps();

            // One reply per person per listing.
            $table->unique(['listing_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('listing_replies');
        Schema::dropIfExists('listing_genre');
        Schema::dropIfExists('listings');
    }
};
