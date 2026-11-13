<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * In-app notifications: the first thing in Riff that goes looking for
     * someone rather than waiting to be opened.
     *
     * Laravel's standard `notifications` shape, deliberately unmodified --
     * it is the table every future notifiable thing (in-app messaging,
     * Phase 4) will also write to, and a custom schema would have to be
     * unpicked to get there.
     *
     * Two preference stores, because there are two questions:
     *
     *  - users.notification_preferences answers "do I want this *kind* of
     *    thing at all" -- a JSON map keyed by App\Support\NotificationType,
     *    so adding a type is a config change rather than a migration.
     *
     *  - artist_follows.notify answers "do I want it *from this band*".
     *    Following a band you love should not mean the same volume as
     *    following one you are curious about, and that dial belongs on the
     *    follow, not on the account.
     */
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The bell asks for "my unread, newest first" on nearly every
            // page; the list page asks for "mine, newest first".
            $table->index(['notifiable_type', 'notifiable_id', 'read_at'], 'notifications_unread_index');
        });

        Schema::table('users', function (Blueprint $table) {
            // Null means "never chosen": the defaults in
            // config('riff.notifications.types') apply, so a new account is
            // not silently opted out of everything.
            $table->json('notification_preferences')->nullable()->after('onboarding_intent');
        });

        Schema::table('artist_follows', function (Blueprint $table) {
            $table->boolean('notify')->default(true)->after('artist_id');
        });
    }

    public function down(): void
    {
        Schema::table('artist_follows', function (Blueprint $table) {
            $table->dropColumn('notify');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });

        Schema::dropIfExists('notifications');
    }
};
