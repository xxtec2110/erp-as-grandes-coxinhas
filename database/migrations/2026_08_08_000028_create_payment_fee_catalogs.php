<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('acquirers', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 60)->nullable()->unique();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
        Schema::create('card_brands', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('code', 60)->nullable()->unique();
            $table->boolean('active')->default(true)->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_brands');
        Schema::dropIfExists('acquirers');
    }
};
