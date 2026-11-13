<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The weekly digest: "your scene, by email".
     *
     * Separate from notification_preferences on purpose. Those answer "do
     * I want to be told about this *kind* of thing"; this answers "do I
     * want email at all, and how often". Someone can happily keep the bell
     * and never want a message in their inbox, and the two should not be
     * entangled.
     *
     * digest_sent_at is both the window ("what has happened since you were
     * last written to") and the cap: the sender refuses to write again
     * inside the frequency, whatever a scheduler or a stray command does.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // off | weekly (config riff.digest.frequencies). A string, not
            // an enum: the CI matrix exists because real drivers reject an
            // out-of-vocabulary enum value that SQLite tolerates.
            $table->string('digest_frequency', 16)->default('weekly')->after('notification_preferences');
            $table->timestamp('digest_sent_at')->nullable()->after('digest_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['digest_frequency', 'digest_sent_at']);
        });
    }
};
