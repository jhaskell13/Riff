<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Shows, their lineups, and who is going.
     *
     * Where a show happens is exactly one of two things: a venue (a
     * recurring place), or the show's own one-off location — a label, a
     * town, and a note. A one-off location never gets a street and never
     * becomes a venue row, so a backyard leaves the map when the night ends.
     *
     * Times are stored in UTC alongside the show's IANA timezone, and are
     * always rendered in that timezone: "doors at 8" means 8 where the show
     * is, whoever is looking.
     */
    public function up(): void
    {
        Schema::create('shows', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();

            // The artist whose members created and manage the listing.
            // Null only for imported listings (Step 8).
            $table->foreignId('host_artist_id')->nullable()->constrained('artists')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->foreignId('venue_id')->nullable()->constrained()->nullOnDelete();
            $table->string('location_name')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('location_note')->nullable();
            // Town-level, and only for one-off locations; a venue show is
            // placed by its venue.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();

            $table->dateTime('starts_at');
            $table->dateTime('doors_at')->nullable();
            $table->string('timezone', 64);

            $table->string('price', 40)->nullable();
            $table->string('ticket_url')->nullable();
            $table->string('age_policy', 16)->nullable();
            $table->text('description')->nullable();

            // member | imported. Imported listings are labelled everywhere
            // and never outrank artist-confirmed ones.
            $table->string('source', 16)->default('member');
            $table->string('provider', 32)->nullable();
            $table->string('provider_ref')->nullable();

            // Cancelled by its hosts: the page stays up and says so, because
            // people holding the link need to know. Soft delete is reserved
            // for moderators taking a listing down.
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('starts_at');
            $table->index(['venue_id', 'starts_at']);
            $table->index(['host_artist_id', 'starts_at']);
            $table->unique(['provider', 'provider_ref']);
        });

        Schema::create('lineup_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            // Exactly one of artist_id / name: a Riff artist, or free text
            // for an act that isn't on Riff.
            $table->foreignId('artist_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            // confirmed | pending | declined. Only confirmed entries are
            // ever shown publicly: one band cannot put another on a bill.
            $table->string('status', 16)->default('confirmed');
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['show_id', 'artist_id']);
            $table->index(['artist_id', 'status']);
        });

        Schema::create('show_rsvps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('show_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // going | interested
            $table->string('status', 16);
            // Private by default: only counts are public unless the fan
            // chooses to be listed.
            $table->boolean('is_public')->default(false);
            $table->timestamps();

            $table->unique(['show_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('show_rsvps');
        Schema::dropIfExists('lineup_entries');
        Schema::dropIfExists('shows');
    }
};
