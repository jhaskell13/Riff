<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Posts gain an explicit voice.
     *
     * Until now a post carried a user_id and a nullable artist_id, so "the
     * band announced this" and "a fan wrote this" were the same shape and
     * had to be guessed at from whether artist_id was set. From here the
     * two axes are named separately:
     *
     *   artist_id      -- the *wall* the post lives on (whose page it is)
     *   author_context -- the *voice* it speaks in: artist or fan
     *   user_id        -- who actually typed it, always recorded, and never
     *                     published when the voice is the artist's
     *
     * An artist-voice post has artist_id = the author = the wall, which is
     * right: artists post on their own page. artist_id stays nullable
     * because the old global feed survives (demoted, not deleted) and its
     * personal posts belong to no wall.
     *
     * The default exists only so the column can be added to a development
     * database that already has rows -- no code relies on it, and every
     * write sets the voice explicitly from the authorization result rather
     * than from the request. Seed data is rewritten rather than backfilled
     * (owner decision, November 2026): valid only while Riff is pre-launch.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->string('author_context', 16)->default('fan')->after('artist_id');

            // The artist page reads one wall, newest first. The existing
            // (status, published_at) index still serves the global feed.
            $table->index(['artist_id', 'status', 'published_at'], 'posts_wall_index');

            // **posts.comments must go before Post::comments() can work.**
            // It is an integer counter column from the original schema that
            // nothing has ever maintained, and -- exactly like the `likes`
            // trap CLAUDE.md documents -- an attribute of that name shadows
            // the relation: $post->comments returned 0, not the replies, so
            // eager loading worked and reading it did not.
            //
            // `likes` stays, because it *is* maintained (ReactionController)
            // and its relation is deliberately named likers(). Comment
            // counts come from withCount(), like followers_count does.
            $table->dropColumn('comments');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->dropIndex('posts_wall_index');
            $table->dropColumn('author_context');
            $table->unsignedBigInteger('comments')->default(0);
        });
    }
};
