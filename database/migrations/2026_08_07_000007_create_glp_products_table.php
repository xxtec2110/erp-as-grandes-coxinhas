<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('glp_products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->decimal('net_weight_kg', 12, 4);
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('glp_products');
    }
};
