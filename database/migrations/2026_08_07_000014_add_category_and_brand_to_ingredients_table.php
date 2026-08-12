<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->foreignId('ingredient_category_id')
                ->nullable()
                ->after('name')
                ->constrained()
                ->restrictOnDelete();
            $table->string('brand')->nullable()->after('ingredient_category_id');
            $table->index('brand');
        });
    }

    public function down(): void
    {
        Schema::table('ingredients', function (Blueprint $table): void {
            $table->dropForeign(['ingredient_category_id']);
            $table->dropIndex(['brand']);
            $table->dropColumn(['ingredient_category_id', 'brand']);
        });
    }
};
