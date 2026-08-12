<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('production_equipment', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('type');
            $table->text('description')->nullable();
            $table->string('energy_source', 20);
            $table->decimal('nominal_glp_consumption_kg_hour', 12, 6)->nullable();
            $table->decimal('power', 12, 4)->nullable();
            $table->string('power_unit', 20)->nullable();
            $table->decimal('default_utilization_factor', 6, 3)->default(1);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['energy_source', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('production_equipment');
    }
};
