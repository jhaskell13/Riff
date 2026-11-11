<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may work the report queue: a single flag, not a role system. Not
     * fillable and with no form behind it -- riff:moderator is the only way
     * in, so no request can ever grant it.
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
