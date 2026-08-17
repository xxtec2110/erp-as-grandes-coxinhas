<?php

namespace App\Http\Controllers;

use App\Http\Requests\CostAnalysisRequest;
use App\Models\Product;
use App\Models\ProductCostSnapshot;
use App\Services\IngredientPriceAnalyticsService;
use App\Services\ProductMarginService;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class CostAnalysisController extends Controller
{
    public function __invoke(CostAnalysisRequest $request, ProductMarginService $margins, IngredientPriceAnalyticsService $ingredientPrices): View
    {
        $date = $request->date('date')?->toDateString();
        $referenceDate = $date === null ? now() : Carbon::parse($date)->endOfDay();
        $rows = $margins->report($date)->map(function (array $row) use ($referenceDate): array {
            $variations = collect([7, 30, 90])->mapWithKeys(function (int $days) use ($row, $referenceDate): array {
                if ($row['snapshot'] === null) {
                    return [$days => null];
                }
                $old = ProductCostSnapshot::query()->where('product_id', $row['product']->id)->where('effective_at', '<=', $referenceDate->copy()->subDays($days))->latest('effective_at')->first();
                if ($old === null || BigDecimal::of($old->unit_cost)->isZero()) {
                    return [$days => null];
                }
                $variation = BigDecimal::of($row['unit_cost'])->minus($old->unit_cost)->multipliedBy(100)->dividedBy($old->unit_cost, 4, RoundingMode::HalfUp);

                return [$days => (string) $variation];
            })->all();
            $marginVariations = collect([7, 30, 90])->mapWithKeys(function (int $days) use ($row, $referenceDate): array {
                if ($row['gross_margin_percentage'] === null) {
                    return [$days => null];
                }
                $old = ProductCostSnapshot::query()->where('product_id', $row['product']->id)->where('effective_at', '<=', $referenceDate->copy()->subDays($days))->latest('effective_at')->first();
                if ($old?->gross_margin_percentage === null) {
                    return [$days => null];
                }

                return [$days => (string) BigDecimal::of($row['gross_margin_percentage'])->minus($old->gross_margin_percentage)->toScale(4, RoundingMode::HalfUp)];
            })->all();

            $row['variations'] = $variations;
            $row['margin_variations'] = $marginVariations;

            return $row;
        });

        $period = (string) $request->input('period', '30');
        $endDate = $period === 'custom' ? $request->date('end_date')->endOfDay() : now()->endOfDay();
        $startDate = $period === 'custom' ? $request->date('start_date')->startOfDay() : $endDate->copy()->subDays((int) $period)->startOfDay();
        $productDependencies = Product::query()->with(['recipe.ingredients', 'recipe.preparations.preparation.preparationIngredients'])->get();
        $ingredientVariations = $ingredientPrices->variationReport($startDate, $endDate)->map(function (array $row) use ($productDependencies): array {
            $ingredientId = $row['ingredient']->id;
            $row['impacted_products'] = $productDependencies->filter(function (Product $product) use ($ingredientId): bool {
                if ($product->recipe === null) {
                    return false;
                }
                if ($product->recipe->ingredients->contains('ingredient_id', $ingredientId)) {
                    return true;
                }

                return $product->recipe->preparations->contains(fn ($item) => $item->preparation->preparationIngredients->contains('ingredient_id', $ingredientId));
            })->pluck('name')->values();

            return $row;
        });

        return view('costs.index', compact('rows', 'date', 'period', 'startDate', 'endDate', 'ingredientVariations'));
    }
}
