<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * What a listener told us about an artist or a post, and what a
     * moderator did about it. Polymorphic because the two subjects Riff has
     * today will not be the last, and the queue reads the same either way.
     *
     * Nothing is deleted here: the resolution columns are the audit trail,
     * so a dismissed report stays readable next to the action it did not
     * cause. A reporter leaving Riff nulls their id rather than erasing the
     * report, which would take the record of the decision with it.
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

            // What the moderator did, who they were, and why. Set together
            // when a report leaves the queue; null for everything still in it.
            $table->string('resolution', 32)->nullable();
            $table->foreignId('resolved_by_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->text('moderator_note')->nullable();
            $table->timestamps();

            // The queue is "open reports, oldest first". morphs() already
            // indexes the subject, which is how resolving one report closes
            // the others about the same artist.
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reports');
    }
};
