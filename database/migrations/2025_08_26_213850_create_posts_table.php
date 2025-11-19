<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('artist_id')->constrained()->onDelete('cascade')->nullable();
            $table->string('author');
            $table->text('content')->nullable();
            $table->binary('media')->nullable();
            $table->enum('status', ['draft', 'published', 'archived'])->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->unsignedBigInteger('likes')->default(0);
            $table->unsignedBigInteger('comments')->default(0);
            $table->json('meta_data')->nullable();
            $table->timestamps();

            $table->index(['status', 'published_at']);
            $table->index('user_id');
            $table->index('artist_id');
        });

        Schema::create('post_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Ensure a user can only like a post once
            $table->unique(['post_id', 'user_id']);
            $table->index('post_id');
            $table->index('user_id');
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('parent_id')->nullable()->constrained('comments')->onDelete('cascade');
            $table->text('content');
            $table->unsignedBigInteger('likes')->default(0);
            $table->timestamps();

            $table->index('post_id');
            $table->index('parent_id');
        });

        Schema::create('comment_likes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->timestamps();

            // Ensure a user can only like a comment once
            $table->unique(['comment_id', 'user_id']);
            $table->index('comment_id');
            $table->index('user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('posts');
        Schema::dropIfExists('post_likes');
        Schema::dropIfExists('comments');
        Schema::dropIfExists('comment_likes');
    }
};
