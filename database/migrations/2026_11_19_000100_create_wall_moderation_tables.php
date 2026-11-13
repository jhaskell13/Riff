<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Artist-level moderation: members keeping their own page tidy.
     *
     * **Artists hide; they never delete or archive.** Hiding takes
     * something off the public wall and leaves the row exactly where it
     * was, so every action has an inverse and nothing a fan wrote is ever
     * destroyed by someone else. Taking content *down* stays a platform
     * moderator's decision, reached through a report.
     *
     * Two tables:
     *
     *  - wall_moderation_events is the trail. Every hide, unhide, ban and
     *    unban is appended here, never updated, so "who hid this and when,
     *    and who put it back" is answerable for any one post or comment.
     *    An artist who hides the wrong thing needs to see what they did.
     *
     *  - artist_wall_bans is who may no longer post on one artist's wall.
     *    A ban is about the wall, not the fandom: a banned listener keeps
     *    following and keeps reading.
     */
    public function up(): void
    {
        Schema::create('wall_moderation_events', function (Blueprint $table) {
            $table->id();
            // Whose wall the action was taken on.
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            // Which member took it. Nullable so the trail survives an
            // account being deleted -- an audit trail that disappears with
            // its author is not an audit trail.
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            // hide | unhide | ban | unban (App\Support\WallAction).
            $table->string('action', 16);
            // The Post, Comment, or User (for a ban) acted on.
            $table->morphs('subject');
            $table->string('note', 500)->nullable();
            $table->timestamps();

            // The trail for one entity, newest first.
            $table->index(['subject_type', 'subject_id', 'created_at'], 'wall_events_subject_index');
            // And the whole log for one artist.
            $table->index(['artist_id', 'created_at']);
        });

        Schema::create('artist_wall_bans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('banned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 500)->nullable();
            $table->timestamps();

            // One ban per person per wall; lifting it deletes the row, and
            // the trail remembers that it happened.
            $table->unique(['artist_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_wall_bans');
        Schema::dropIfExists('wall_moderation_events');
    }
};
