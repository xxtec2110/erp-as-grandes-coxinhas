<?php

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\IngredientCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientCategoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_routes_require_authentication(): void
    {
        $this->get(route('ingredient-categories.index'))->assertRedirect(route('login'));
        $this->post(route('ingredient-categories.store'), [])->assertRedirect(route('login'));
    }

    public function test_authenticated_user_can_create_and_update_an_ingredient_category(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('ingredient-categories.store'), [
            'name' => 'Laticínios',
            'notes' => 'Queijos e derivados.',
            'active' => '1',
        ])->assertRedirect(route('ingredient-categories.index'));

        $category = IngredientCategory::query()->firstOrFail();
        $this->put(route('ingredient-categories.update', $category), [
            'name' => 'Laticínios e derivados',
            'notes' => 'Categoria revisada.',
        ])->assertRedirect(route('ingredient-categories.index'));

        $this->assertDatabaseHas('ingredient_categories', [
            'id' => $category->id,
            'name' => 'Laticínios e derivados',
            'active' => false,
        ]);
    }

    public function test_ingredient_can_be_created_with_category_and_brand(): void
    {
        $category = IngredientCategory::query()->create(['name' => 'Farinhas', 'active' => true]);

        $response = $this->actingAs(User::factory()->create())->post(route('ingredients.store'), [
            'name' => 'Farinha de trigo',
            'ingredient_category_id' => $category->id,
            'brand' => 'Marca Teste',
            'base_unit' => 'g',
            'active' => '1',
        ]);

        $ingredient = Ingredient::query()->firstOrFail();
        $response->assertRedirect(route('ingredients.show', $ingredient));
        $this->assertSame($category->id, $ingredient->ingredient_category_id);
        $this->assertSame('Marca Teste', $ingredient->brand);

        $this->get(route('ingredients.show', $ingredient))
            ->assertOk()
            ->assertSee('Farinhas')
            ->assertSee('Marca Teste');
    }

    public function test_category_and_brand_are_optional_for_existing_ingredient_flow(): void
    {
        $response = $this->actingAs(User::factory()->create())->post(route('ingredients.store'), [
            'name' => 'Sal',
            'base_unit' => 'g',
            'active' => '1',
        ]);

        $ingredient = Ingredient::query()->firstOrFail();
        $response->assertRedirect(route('ingredients.show', $ingredient));
        $this->assertNull($ingredient->ingredient_category_id);
        $this->assertNull($ingredient->brand);
    }

    public function test_inactive_current_category_remains_available_when_editing_ingredient(): void
    {
        $category = IngredientCategory::query()->create(['name' => 'Antiga', 'active' => false]);
        $ingredient = Ingredient::query()->create([
            'name' => 'Insumo legado',
            'ingredient_category_id' => $category->id,
            'base_unit' => 'g',
            'active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('ingredients.edit', $ingredient))
            ->assertOk()
            ->assertSee('Antiga');
    }
}
