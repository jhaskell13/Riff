<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Towns that have a scene page (/scenes/{slug}). Synced from the
     * coordinates already stored on town-level records — artists, private
     * venues, one-off shows — by riff:sync-places and by GeocodeAddress;
     * nothing here is ever geocoded. Rows are never removed, so a scene link
     * keeps working (as an empty scene) if a town's last artist leaves.
     */
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table) {
            $table->id();
            // "hartford-ct": Place::slugFor(city, region), deterministic, so
            // anything that knows a town can link to its scene.
            $table->string('slug')->unique();
            $table->string('name');
            $table->string('region')->nullable();
            $table->string('country')->nullable();
            $table->decimal('lat', 10, 7);
            $table->decimal('lng', 10, 7);
            // Null means config('riff.scenes.radius_km').
            $table->decimal('radius_km', 6, 1)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
