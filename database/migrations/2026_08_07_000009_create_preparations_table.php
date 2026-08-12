<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparations', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('initial_quantity', 14, 6)->nullable();
            $table->string('initial_unit', 3)->nullable();
            $table->decimal('expected_yield', 14, 6);
            $table->string('yield_unit', 3);
            $table->decimal('actual_final_quantity', 14, 6)->nullable();
            $table->unsignedInteger('total_preparation_time_minutes');
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['active', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparations');
    }
};
