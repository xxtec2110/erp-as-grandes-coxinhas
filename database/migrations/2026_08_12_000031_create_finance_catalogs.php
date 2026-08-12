<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_categories', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->boolean('active')->default(true)->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('cost_centers', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('active')->default(true)->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
        Schema::create('financial_accounts', function (Blueprint $t): void {
            $t->id();
            $t->string('name');
            $t->string('institution')->nullable();
            $t->string('type', 40);
            $t->string('owner_name')->nullable();
            $t->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $t->boolean('active')->default(true)->index();
            $t->text('notes')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
        Schema::dropIfExists('cost_centers');
        Schema::dropIfExists('finance_categories');
    }
};
