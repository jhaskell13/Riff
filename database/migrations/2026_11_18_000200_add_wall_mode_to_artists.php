<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Who may post on this artist's wall: fans (the default), everyone, or
     * members_only. A string validated by Rule::in against
     * config('riff.wall.modes'), not an enum -- MySQL and Postgres reject an
     * out-of-vocabulary enum value that SQLite would quietly accept.
     *
     * The default is "fans" on purpose: being a fan should mean something,
     * and following is a free and effective spam gate. Opening the wall to
     * anyone is the artist's own call to make, which is why it is a setting
     * and not a platform policy.
     */
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->string('wall_mode', 16)->default('fans')->after('show_on_map');
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->dropColumn('wall_mode');
        });
    }
};
