<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_aliases', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('normalized_name')->unique();
            $table->timestampsTz();

            $table->index(['product_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_aliases');
    }
};
