<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a listener reported and what a moderator did about it. Nothing
     * is deleted: the resolution columns are the audit trail, and a
     * departing reporter nulls their id rather than erasing the record.
     */
    public function up(): void
    {
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->morphs('reportable');
            $table->foreignId('reporter_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason', 32);
            $table->text('details')->nullable();
            $table->string('status', 16)->default('open');

            // Set together when a report leaves the queue.
            $table->string('resolution', 32)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('moderator_note')->nullable();
            $table->timestamps();

            // The queue reads "open, oldest first"; morphs() indexed the subject.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
