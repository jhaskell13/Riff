<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * posts.status was an enum of draft/published/archived; moderation's
     * "remove post" writes 'removed', which SQLite accepted but MySQL and
     * Postgres reject through the enum's constraint. Now a plain string:
     * the vocabulary belongs to the code, and the feed only ever selects
     * status = 'published'.
     */
    public function up(): void
    {
        // Laravel's Postgres enum is a varchar with a check constraint,
        // which change() alone leaves behind.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table posts drop constraint if exists posts_status_check');
        }

        Schema::table('posts', function (Blueprint $table) {
            $table->string('status', 16)->default('draft')->change();
        });
    }

    public function down(): void
    {
        // Left as a string: reinstating the enum would reject any rows a
        // moderator has since set to 'removed'.
    }
};
