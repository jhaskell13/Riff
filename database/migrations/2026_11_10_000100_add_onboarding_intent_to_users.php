<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether someone joined to listen or to be heard (OnboardingIntent).
     * On the user, not the session, so the artist path survives the
     * email-verification hop. Null for accounts that predate the paths.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('onboarding_intent', 16)
                ->nullable()
                ->after('distance_unit');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('onboarding_intent');
        });
    }
};
