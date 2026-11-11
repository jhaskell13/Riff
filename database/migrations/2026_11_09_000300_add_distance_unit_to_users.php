<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Map distances are shown in the listener's own unit. Registration
     * guesses it from the stated country; the profile page can change it.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('distance_unit', 2)
                ->default(config('riff.distance.default_unit', 'mi'))
                ->after('country');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('distance_unit');
        });
    }
};
