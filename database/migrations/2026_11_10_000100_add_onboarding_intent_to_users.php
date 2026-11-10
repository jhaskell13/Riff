<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Whether someone joined to listen or to be heard (App\Support\
     * OnboardingIntent). Kept on the user because registration and the
     * email-verification hop that follows it are separate requests, often
     * from different browsers, and the artist path has to resume after it.
     * Null for everyone who registered before the two paths existed.
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
