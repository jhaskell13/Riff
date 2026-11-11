<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Coordinates are geocoded once when an address is saved and read many
     * times by the map, so they live on the row. Nullable: a failed or
     * pending geocode means "not on the map yet", never a fake location.
     */
    public function up(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('country');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
            $table->index(['lat', 'lng']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->decimal('lat', 10, 7)->nullable()->after('country');
            $table->decimal('lng', 10, 7)->nullable()->after('lat');
        });
    }

    public function down(): void
    {
        Schema::table('artists', function (Blueprint $table) {
            $table->dropIndex(['lat', 'lng']);
            $table->dropColumn(['lat', 'lng']);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['lat', 'lng']);
        });
    }
};
