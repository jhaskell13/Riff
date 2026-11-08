<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A user can only be a member of an artist once; the like pivot
     * tables already had this guarantee.
     */
    public function up(): void
    {
        Schema::table('artist_user', function (Blueprint $table) {
            $table->unique(['artist_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('artist_user', function (Blueprint $table) {
            $table->dropUnique(['artist_id', 'user_id']);
        });
    }
};
