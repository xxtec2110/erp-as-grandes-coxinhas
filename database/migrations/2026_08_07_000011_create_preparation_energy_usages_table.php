<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preparation_energy_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('preparation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('production_equipment_id')->constrained('production_equipment')->restrictOnDelete();
            $table->foreignId('equipment_burner_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('glp_product_id')->constrained()->restrictOnDelete();
            $table->decimal('usage_time_minutes', 10, 2);
            $table->decimal('utilization_factor', 6, 3);
            $table->timestamps();

            $table->index(['preparation_id', 'production_equipment_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preparation_energy_usages');
    }
};
