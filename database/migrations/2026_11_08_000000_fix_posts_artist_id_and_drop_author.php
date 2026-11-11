<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The original posts migration chained ->nullable() after ->constrained(),
     * which applies it to the foreign key definition instead of the column, so
     * artist_id ended up NOT NULL and personal (non-artist) posts could never
     * be stored. The author string column is dropped: the display name belongs
     * to the user/artist relations, not a copied-in column.
     */
    public function up(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable()->change();
            $table->dropColumn('author');
        });
    }

    public function down(): void
    {
        Schema::table('posts', function (Blueprint $table) {
            $table->foreignId('artist_id')->nullable(false)->change();
            $table->string('author')->default('');
        });
    }
};
