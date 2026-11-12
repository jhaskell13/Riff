<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * MySQL indexes foreign keys by itself; SQLite and Postgres do not. These
     * back the Artist Zone's "my listings" / "my venues" lookups, and the
     * cascades that run when an account is deleted.
     */
    public function up(): void
    {
        Schema::table('listings', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('venues', fn (Blueprint $table) => $table->index('submitted_by'));
        Schema::table('shows', fn (Blueprint $table) => $table->index('created_by'));
        Schema::table('show_rsvps', fn (Blueprint $table) => $table->index('user_id'));
        Schema::table('listing_replies', fn (Blueprint $table) => $table->index('user_id'));
    }

    public function down(): void
    {
        Schema::table('listings', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('venues', fn (Blueprint $table) => $table->dropIndex(['submitted_by']));
        Schema::table('shows', fn (Blueprint $table) => $table->dropIndex(['created_by']));
        Schema::table('show_rsvps', fn (Blueprint $table) => $table->dropIndex(['user_id']));
        Schema::table('listing_replies', fn (Blueprint $table) => $table->dropIndex(['user_id']));
    }
};
