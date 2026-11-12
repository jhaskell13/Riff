<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Where an artist already gets paid. Riff links out and handles no
     * money (ROADMAP principle 4), so there is deliberately nothing here
     * about orders, prices, or payouts -- only a typed link and a counter.
     *
     * clicks is an aggregate on purpose: the question the Phase 4 payments
     * decision gate asks is "do support links get clicked", not who clicked
     * one. No per-user click rows exist to leak.
     */
    public function up(): void
    {
        Schema::create('artist_support_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('artist_id')->constrained()->cascadeOnDelete();
            // A key from config('riff.support.types'). A string, not an
            // enum: MySQL and Postgres reject an out-of-vocabulary enum
            // value that SQLite would accept (see the CI matrix).
            $table->string('type', 32);
            $table->string('url', 2048);
            // Optional override for the type's label ("Tour shirts").
            $table->string('label', 40)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->unsignedBigInteger('clicks')->default(0);
            $table->timestamps();

            // The artist page reads every link for one artist, in order.
            $table->index(['artist_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('artist_support_links');
    }
};
