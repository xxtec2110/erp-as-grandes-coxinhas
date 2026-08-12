<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('equipment_burners', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('production_equipment_id')
                ->constrained('production_equipment')
                ->cascadeOnDelete();
            $table->string('name');
            $table->string('type', 30);
            $table->decimal('nominal_glp_consumption_kg_hour', 12, 6);
            $table->decimal('power', 12, 4)->nullable();
            $table->string('power_unit', 20)->nullable();
            $table->decimal('default_utilization_factor', 6, 3)->default(1);
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index(['production_equipment_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('equipment_burners');
    }
};
