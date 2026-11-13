<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Comments become polymorphic, and gain a voice and a status.
     *
     * Why polymorphic: show threads (Phase 3 step 5) are a discussion on a
     * *show*, not on a post. The alternative -- giving every show a phantom
     * post row to hang comments from -- leaks into every query that reads
     * posts, forever, to work around a foreign key. Listings and venues may
     * want threads later for the same reason.
     *
     * Why drop and recreate rather than ALTER: the table has never had a UI
     * (the schema has been dormant since 2025), so there is nothing to
     * preserve, and recreating sidesteps three drivers' separate opinions
     * about dropping a constrained column. Seed data is rewritten rather
     * than backfilled (owner decision, November 2026) -- valid only while
     * Riff is pre-launch; the first real user's comment ends it.
     *
     * comment_likes goes first and comes back after: it holds a foreign key
     * into comments.
     */
    public function up(): void
    {
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comments');

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            // What is being discussed: a Post today, a Show from step 5.
            $table->morphs('commentable');
            // Who typed it. Always recorded, for the audit trail and for
            // artist-level moderation; never published when the voice is
            // the artist's.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // The voice: 'artist' or 'fan'. A member replying as the band
            // is the single most important signal on a wall.
            $table->string('author_context', 16)->default('fan');
            // Which artist is speaking, when the voice is the artist's.
            $table->foreignId('artist_id')->nullable()->constrained()->cascadeOnDelete();
            // One level only: a reply points at a top-level comment, and
            // the request refuses to go deeper.
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('content');
            // published | hidden (by the artist) | removed (by a moderator).
            // Nothing is destroyed: the row stays and the status changes.
            $table->string('status', 16)->default('published');
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();

            // Reading one post's thread, oldest first: a conversation reads
            // forwards, unlike a wall.
            $table->index(['commentable_type', 'commentable_id', 'status', 'created_at'], 'comments_thread_index');
            $table->index('parent_id');
            $table->index('artist_id');
        });

        Schema::create('comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comment_likes');
        Schema::dropIfExists('comments');

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('comments')->cascadeOnDelete();
            $table->text('content');
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();

            $table->index('post_id');
            $table->index('parent_id');
        });

        Schema::create('comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['comment_id', 'user_id']);
        });
    }
};
