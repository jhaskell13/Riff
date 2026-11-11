<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::rename('artist_users', 'artist_user');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::rename('artist_user', 'artist_users');
    }
};
