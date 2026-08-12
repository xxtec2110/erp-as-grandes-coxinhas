<?php

namespace Tests\Feature;

use App\Models\GlpProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlpManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_glp_routes_require_authentication(): void
    {
        $this->get(route('glp-products.index'))->assertRedirect(route('login'));
    }

    public function test_user_can_create_container_and_record_its_calculated_cost(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->post(route('glp-products.store'), [
            'name' => 'P45',
            'net_weight_kg' => '45.0000',
            'notes' => 'Recipiente principal da produção.',
            'active' => '1',
        ]);

        $product = GlpProduct::query()->firstOrFail();
        $response->assertRedirect(route('glp-products.show', $product));

        $this->post(route('glp-products.prices.store', $product), [
            'quantity_kg' => '45.0000',
            'total_price' => '450.00',
            'effective_date' => '2026-08-07',
        ])->assertRedirect(route('glp-products.show', $product));

        $this->assertDatabaseHas('glp_prices', [
            'glp_product_id' => $product->id,
            'unit_cost_per_kg' => '10.00000000',
            'is_current' => true,
        ]);

        $this->get(route('glp-products.show', $product))
            ->assertOk()
            ->assertSee('R$ 450,00')
            ->assertSee('R$ 10,0000');
    }

    public function test_new_current_glp_price_preserves_previous_history(): void
    {
        $product = GlpProduct::query()->create([
            'name' => 'P13',
            'net_weight_kg' => '13.0000',
            'active' => true,
        ]);

        foreach ([['130.00', '2026-08-01'], ['156.00', '2026-08-07']] as [$price, $date]) {
            $this->actingAs(User::factory()->create())
                ->post(route('glp-products.prices.store', $product), [
                    'quantity_kg' => '13.0000',
                    'total_price' => $price,
                    'effective_date' => $date,
                    'is_current' => '1',
                ])
                ->assertSessionHasNoErrors();
        }

        $this->assertSame(2, $product->prices()->count());
        $this->assertSame(1, $product->prices()->where('is_current', true)->count());
        $this->assertSame('12.00000000', $product->currentPrice()->firstOrFail()->unit_cost_per_kg);
    }

    public function test_glp_price_rejects_invalid_decimal_values(): void
    {
        $product = GlpProduct::query()->create([
            'name' => 'P20',
            'net_weight_kg' => '20.0000',
            'active' => true,
        ]);

        $this->actingAs(User::factory()->create())
            ->post(route('glp-products.prices.store', $product), [
                'quantity_kg' => '0',
                'total_price' => '100.999',
                'effective_date' => '2026-08-07',
            ])
            ->assertSessionHasErrors(['quantity_kg', 'total_price']);

        $this->assertDatabaseCount('glp_prices', 0);
    }
}
