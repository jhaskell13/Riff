<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may work the report queue. Deliberately a single flag rather than
     * a role system: the pilot needs one trusted person, and an unused
     * permission model is its own kind of risk.
     *
     * The column is not fillable on the model and has no form behind it --
     * `php artisan riff:moderator` is the only way in, so no request can
     * ever grant it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_moderator')->default(false)->after('onboarding_intent');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_moderator');
        });
    }
};
