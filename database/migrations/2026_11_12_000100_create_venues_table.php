<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Established places that host music regularly. One-off show locations
     * are deliberately not stored here (see config/riff.php, venues).
     *
     * Columns are city/state, not the artists table's historical
     * hometown/stateOrProvince; Geocodable papers over the difference.
     * Every vocabulary column is a plain string validated in code: the CI
     * matrix exists because real drivers reject what SQLite tolerates.
     */
    public function up(): void
    {
        Schema::create('venues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('kind', 32);
            $table->string('address_visibility', 16)->default('public');
            // Null whenever the venue is private: never stored, so never
            // leaked. The model enforces this on save, not only the form.
            $table->string('street_address')->nullable();
            $table->string('city');
            $table->string('state');
            $table->string('country')->nullable();
            $table->string('age_policy', 16)->nullable();
            $table->string('website')->nullable();
            $table->text('description')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Moderator-controlled (a hidden venue leaves the map); not
            // fillable, so a submitter cannot undo a moderator's decision.
            $table->boolean('show_on_map')->default(true);
            $table->foreignId('submitted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            // Same covering shape as artists_map_index, ready for the map
            // (Phase 2, Step 4).
            $table->index(['show_on_map', 'deleted_at', 'lat', 'lng'], 'venues_map_index');
            // Duplicate check on submission: same name in the same town.
            $table->index(['city', 'state']);
        });

        Schema::create('venue_slugs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->cascadeOnDelete();
            $table->string('slug')->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_slugs');
        Schema::dropIfExists('venues');
    }
};
