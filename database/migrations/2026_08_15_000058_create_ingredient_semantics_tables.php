<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredient_concepts', function (Blueprint $table): void {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->boolean('active')->default(true)->index();
            $table->boolean('is_protected')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('ingredient_business_terms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_concept_id')->constrained()->restrictOnDelete();
            $table->string('term');
            $table->string('normalized_term')->unique();
            $table->boolean('active')->default(true)->index();
            $table->boolean('is_protected')->default(false)->index();
            $table->timestamps();
        });

        Schema::create('ingredient_concept_bindings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ingredient_concept_id')->constrained()->restrictOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->timestamp('effective_from');
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->timestamps();

            $table->index(['ingredient_concept_id', 'effective_until'], 'ingredient_concept_bindings_current_index');
        });

        DB::statement('CREATE UNIQUE INDEX ingredient_concept_bindings_one_current ON ingredient_concept_bindings (ingredient_concept_id) WHERE effective_until IS NULL');

        $now = now();
        $conceptId = DB::table('ingredient_concepts')->insertGetId([
            'code' => 'requeijao',
            'name' => 'Requeijão',
            'active' => true,
            'is_protected' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('ingredient_business_terms')->insert([
            [
                'ingredient_concept_id' => $conceptId,
                'term' => 'catupiry',
                'normalized_term' => 'catupiry',
                'active' => true,
                'is_protected' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'ingredient_concept_id' => $conceptId,
                'term' => 'requeijão',
                'normalized_term' => 'requeijao',
                'active' => true,
                'is_protected' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredient_concept_bindings');
        Schema::dropIfExists('ingredient_business_terms');
        Schema::dropIfExists('ingredient_concepts');
    }
};
