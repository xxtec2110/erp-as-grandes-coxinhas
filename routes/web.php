<?php

use App\Http\Controllers\AcquirerController;
use App\Http\Controllers\AgentAdministrationController;
use App\Http\Controllers\AgentAttachmentController;
use App\Http\Controllers\AgentSimulatorController;
use App\Http\Controllers\AgentUsageReportController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CardBrandController;
use App\Http\Controllers\CostAnalysisController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DashboardUserVisibilityController;
use App\Http\Controllers\EquipmentBurnerController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\GlpPriceController;
use App\Http\Controllers\GlpProductController;
use App\Http\Controllers\GrandChefConnectionController;
use App\Http\Controllers\GrandChefReportController;
use App\Http\Controllers\IngredientCategoryController;
use App\Http\Controllers\IngredientController;
use App\Http\Controllers\IngredientPriceController;
use App\Http\Controllers\IngredientStockController;
use App\Http\Controllers\LocationController;
use App\Http\Controllers\LossReasonController;
use App\Http\Controllers\OpeningStockController;
use App\Http\Controllers\OperationalReadinessController;
use App\Http\Controllers\OperationalReportController;
use App\Http\Controllers\PaymentFeeController;
use App\Http\Controllers\PdvGoLiveController;
use App\Http\Controllers\PdvIntegrationController;
use App\Http\Controllers\PdvMappingController;
use App\Http\Controllers\PdvOrderController;
use App\Http\Controllers\PdvReconciliationController;
use App\Http\Controllers\PreparationAdditionalCostController;
use App\Http\Controllers\PreparationController;
use App\Http\Controllers\PreparationEnergyUsageController;
use App\Http\Controllers\PreparationIngredientController;
use App\Http\Controllers\ProductCategoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionController;
use App\Http\Controllers\ProductionEquipmentController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\ProductionRequirementController;
use App\Http\Controllers\ProductLossController;
use App\Http\Controllers\ProductRecipeController;
use App\Http\Controllers\ProductSaleController;
use App\Http\Controllers\ProductStockPolicyController;
use App\Http\Controllers\PurchaseDocumentController;
use App\Http\Controllers\PurchaseDocumentImportController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\StockController;
use App\Http\Controllers\StockTransferController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserAccessController;
use App\Http\Controllers\WhatsAppConnectionController;
use App\Models\UserExternalIdentity;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

Route::bind('identity', fn (string $value): UserExternalIdentity => UserExternalIdentity::query()
    ->whereKey($value)
    ->where('channel', 'whatsapp')
    ->firstOrFail());

Route::get('/', function (): View|RedirectResponse {
    return auth()->check()
        ? redirect()->route('dashboard')
        : view('auth.login');
})->name('home');

Route::view('/politica-de-privacidade', 'legal.privacy')
    ->name('privacy-policy');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])
        ->name('login.store');
});

Route::middleware('auth')->group(function (): void {
    Route::post('/anexos', [AgentAttachmentController::class, 'store'])->name('attachments.store');
    Route::get('/anexos/{attachment}/download', [AgentAttachmentController::class, 'download'])->name('attachments.download');
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/configuracoes/simulador-agente', [AgentSimulatorController::class, 'index'])->middleware('permission:users.manage')->name('agent.simulator');
    Route::post('/configuracoes/simulador-agente', [AgentSimulatorController::class, 'send'])->middleware('permission:users.manage')->name('agent.simulator.send');
    Route::get('/configuracoes/agente/identidades', [AgentAdministrationController::class, 'identities'])->middleware('permission:whatsapp.identities.view')->name('agent.identities.index');
    Route::get('/configuracoes/agente/identidades/criar', [AgentAdministrationController::class, 'createIdentity'])->middleware('permission:whatsapp.identities.manage')->name('agent.identities.create');
    Route::post('/configuracoes/agente/identidades', [AgentAdministrationController::class, 'storeIdentity'])->middleware('permission:whatsapp.identities.manage')->name('agent.identities.store');
    Route::get('/configuracoes/agente/identidades/{identity}/editar', [AgentAdministrationController::class, 'editIdentity'])->middleware('permission:whatsapp.identities.view')->name('agent.identities.edit');
    Route::put('/configuracoes/agente/identidades/{identity}', [AgentAdministrationController::class, 'updateIdentity'])->middleware('permission:whatsapp.identities.manage')->name('agent.identities.update');
    Route::put('/configuracoes/agente/identidades/{identity}/telefone', [AgentAdministrationController::class, 'replaceIdentityPhone'])->middleware('permission:whatsapp.identities.manage')->name('agent.identities.phone');
    Route::post('/configuracoes/agente/identidades/{identity}/boas-vindas', [AgentAdministrationController::class, 'welcomeIdentity'])->middleware('permission:whatsapp.identities.manage')->name('agent.identities.welcome');
    Route::get('/configuracoes/agente/observabilidade', [AgentAdministrationController::class, 'observability'])->middleware('permission:users.manage')->name('agent.observability');
    Route::put('/configuracoes/agente/custos', [AgentAdministrationController::class, 'updateCosts'])->middleware('permission:users.manage')->name('agent.costs.update');
    Route::get('/configuracoes/agente/interacoes/{conversation}', [AgentAdministrationController::class, 'interaction'])->middleware('permission:users.manage')->name('agent.interactions.show');
    Route::get('/configuracoes/agente/whatsapp', [WhatsAppConnectionController::class, 'index'])->middleware('permission:agent.whatsapp.manage_connection')->name('agent.whatsapp.index');
    Route::post('/configuracoes/agente/whatsapp/verificar', [WhatsAppConnectionController::class, 'check'])->middleware('permission:agent.whatsapp.manage_connection')->name('agent.whatsapp.check');
    Route::put('/configuracoes/agente/whatsapp/numero-empresarial', [WhatsAppConnectionController::class, 'updateBusinessPhone'])->middleware('permission:agent.whatsapp.manage_connection')->name('agent.whatsapp.business-phone.update');
    Route::get('/configuracoes/integracoes/pdv', fn (): RedirectResponse => redirect()->route('pdv.index'))->middleware('permission:pdv.manage')->name('pdv.legacy-index');
    Route::get('/configuracoes/integracoes/grandchef', [PdvIntegrationController::class, 'index'])->middleware('permission:pdv.manage')->name('pdv.index');
    Route::get('/configuracoes/integracoes/grandchef/unidades/{location}/criar', [GrandChefConnectionController::class, 'create'])->middleware('permission:pdv.manage')->name('pdv.connections.create');
    Route::post('/configuracoes/integracoes/grandchef/unidades/{location}', [GrandChefConnectionController::class, 'store'])->middleware('permission:pdv.manage')->name('pdv.connections.store');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/editar', [GrandChefConnectionController::class, 'edit'])->middleware('permission:pdv.manage')->name('pdv.connections.edit');
    Route::put('/configuracoes/integracoes/grandchef/conexoes/{connection}', [GrandChefConnectionController::class, 'update'])->middleware('permission:pdv.manage')->name('pdv.connections.update');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/vendas', [GrandChefReportController::class, 'index'])->middleware('permission:pdv.manage')->name('pdv.reports.sales');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/pedidos/{externalSaleId}', [GrandChefReportController::class, 'show'])->middleware('permission:pdv.manage')->name('pdv.reports.orders.show');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/conferencia', [PdvOrderController::class, 'index'])->middleware('permission:pdv.manage')->name('pdv.staging.index');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/reconciliacao', PdvReconciliationController::class)->middleware('permission:pdv.manage')->name('pdv.reconciliation');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/conferencia/preparar', [PdvOrderController::class, 'prepare'])->middleware('permission:pdv.manage')->name('pdv.staging.prepare');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/conferencia/{order}', [PdvOrderController::class, 'show'])->middleware('permission:pdv.manage')->name('pdv.staging.show');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/conferencia/{order}/importar', [PdvOrderController::class, 'confirmImport'])->middleware('permission:pdv.manage')->name('pdv.staging.import');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/conferencia/{order}/reverter', [PdvOrderController::class, 'reverse'])->middleware('permission:pdv.manage')->name('pdv.staging.reverse');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/go-live', [PdvGoLiveController::class, 'index'])->middleware('permission:pdv.manage')->name('pdv.go-live');
    Route::put('/configuracoes/integracoes/grandchef/conexoes/{connection}/go-live/marco-operacional', [PdvGoLiveController::class, 'updateOperationalStart'])->middleware('permission:pdv.manage')->name('pdv.go-live.operational-start.update');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/go-live/produtos/previa', [PdvGoLiveController::class, 'previewProducts'])->middleware(['permission:pdv.manage', 'permission:products.create'])->name('pdv.go-live.products.preview');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/go-live/produtos/confirmar', [PdvGoLiveController::class, 'confirmProducts'])->middleware(['permission:pdv.manage', 'permission:products.create'])->name('pdv.go-live.products.confirm');
    Route::get('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos', [PdvMappingController::class, 'index'])->middleware('permission:pdv.manage')->name('pdv.mappings');
    Route::put('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos', [PdvMappingController::class, 'legacyUpdate'])->middleware('permission:pdv.manage')->name('pdv.mappings.update');
    Route::put('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos/produtos/{externalProductId}', [PdvMappingController::class, 'updateProduct'])->middleware('permission:pdv.manage')->name('pdv.mappings.products.update');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos/produtos/lote/previa', [PdvMappingController::class, 'previewProductBatch'])->middleware('permission:pdv.manage')->name('pdv.mappings.products.batch.preview');
    Route::post('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos/produtos/lote/confirmar', [PdvMappingController::class, 'confirmProductBatch'])->middleware('permission:pdv.manage')->name('pdv.mappings.products.batch.confirm');
    Route::put('/configuracoes/integracoes/grandchef/conexoes/{connection}/mapeamentos/pagamentos/{externalFormId}', [PdvMappingController::class, 'updatePayment'])->middleware('permission:pdv.manage')->name('pdv.mappings.payments.update');
    Route::get('/configuracoes/integracoes/pdv/{connection}/mapeamentos', fn (string $connection): RedirectResponse => redirect()->route('pdv.mappings', $connection))->middleware('permission:pdv.manage')->name('pdv.mappings.legacy');
    Route::get('/configuracoes/integracoes/pdv/{connection}/eventos', [PdvIntegrationController::class, 'events'])->middleware('permission:pdv.manage')->name('pdv.events');
    Route::post('/configuracoes/integracoes/pdv/{connection}/testar', [PdvIntegrationController::class, 'test'])->middleware('permission:pdv.manage')->name('pdv.test');
    Route::post('/configuracoes/integracoes/pdv/{connection}/sincronizar', [PdvIntegrationController::class, 'sync'])->middleware('permission:pdv.manage')->name('pdv.sync');
    Route::post('/configuracoes/integracoes/pdv/eventos/{event}/reprocessar', [PdvIntegrationController::class, 'reprocess'])->middleware('permission:pdv.manage')->name('pdv.events.reprocess');
    Route::get('/configuracoes/agente/uso', AgentUsageReportController::class)->middleware('permission:agent.usage.view')->name('agent.usage');

    Route::resource('fornecedores', SupplierController::class)
        ->parameters(['fornecedores' => 'supplier'])
        ->names('suppliers')
        ->except(['show', 'destroy'])
        ->middlewareFor(['index'], 'permission:suppliers.view')
        ->middlewareFor(['create', 'store', 'edit', 'update'], 'permission:suppliers.manage');
    Route::resource('unidades', LocationController::class)
        ->parameters(['unidades' => 'location'])
        ->names('locations')
        ->except(['show', 'destroy'])
        ->middlewareFor(['index'], 'permission:locations.view')
        ->middlewareFor(['create', 'store'], 'permission:locations.create')
        ->middlewareFor(['edit', 'update'], 'permission:locations.update,location');
    Route::get('/produtos', [ProductController::class, 'index'])->middleware('permission:products.view')->name('products.index');
    Route::get('/produtos/criar', [ProductController::class, 'create'])->middleware('permission:products.create')->name('products.create');
    Route::post('/produtos', [ProductController::class, 'store'])->middleware('permission:products.create')->name('products.store');
    Route::get('/produtos/{product}/editar', [ProductController::class, 'edit'])->middleware('permission:products.update')->name('products.edit');
    Route::put('/produtos/{product}', [ProductController::class, 'update'])->middleware('permission:products.update')->name('products.update');
    Route::get('/produtos/{product}/ficha-tecnica', [ProductRecipeController::class, 'edit'])->middleware('permission:product_recipes.view')->name('products.recipe.edit');
    Route::put('/produtos/{product}/ficha-tecnica', [ProductRecipeController::class, 'update'])->middleware('permission:product_recipes.manage')->name('products.recipe.update');
    Route::get('/estoque', [StockController::class, 'index'])->middleware('permission:stock.view')->name('stock.index');
    Route::get('/estoque-inicial', [OpeningStockController::class, 'create'])
        ->middleware('permission:stock.opening_balance')->name('stock.opening.create');
    Route::post('/estoque-inicial/previa', [OpeningStockController::class, 'preview'])
        ->middleware('permission:stock.opening_balance')->name('stock.opening.preview');
    Route::post('/estoque-inicial/confirmar', [OpeningStockController::class, 'store'])
        ->middleware('permission:stock.opening_balance')->name('stock.opening.store');
    Route::get('/estoque/{product}/{location}', [StockController::class, 'show'])->middleware('permission:stock.view,location')->name('stock.show');
    Route::get('/estoque/{product}/{location}/ajustar', [StockAdjustmentController::class, 'create'])
        ->middleware('permission:stock.adjust,location')->name('stock.adjustments.create');
    Route::post('/estoque/{product}/{location}/ajustar', [StockAdjustmentController::class, 'store'])
        ->middleware('permission:stock.adjust,location')->name('stock.adjustments.store');
    Route::get('/producao', [ProductionController::class, 'index'])->middleware('permission:production.view')->name('production.index');
    Route::get('/producao/criar', [ProductionController::class, 'create'])->middleware('permission:production.create')->name('production.create');
    Route::post('/producao', [ProductionController::class, 'store'])->middleware('permission:production.create')->name('production.store');
    Route::get('/producao/{production}', [ProductionController::class, 'show'])->middleware('permission:production.view')->name('production.show');
    Route::post('/producao/{production}/concluir', [ProductionController::class, 'complete'])->middleware('permission:production.create')->name('production.complete');
    Route::post('/producao/{production}/cancelar', [ProductionController::class, 'cancel'])->middleware('permission:production.create')->name('production.cancel');
    Route::get('/ordens-producao', [ProductionOrderController::class, 'index'])->middleware('permission:production.orders.view')->name('production-orders.index');
    Route::get('/ordens-producao/criar', [ProductionOrderController::class, 'create'])->middleware('permission:production.orders.create')->name('production-orders.create');
    Route::post('/ordens-producao', [ProductionOrderController::class, 'store'])->middleware('permission:production.orders.create')->name('production-orders.store');
    Route::get('/ordens-producao/{order}', [ProductionOrderController::class, 'show'])->middleware('permission:production.orders.view')->name('production-orders.show');
    Route::post('/ordens-producao/{order}/concluir', [ProductionOrderController::class, 'complete'])->middleware('permission:production.orders.complete')->name('production-orders.complete');
    Route::post('/ordens-producao/{order}/reverter', [ProductionOrderController::class, 'reverse'])->middleware('permission:production.orders.reverse')->name('production-orders.reverse');
    Route::get('/transferencias', [StockTransferController::class, 'index'])->middleware('permission:transfers.view')->name('transfers.index');
    Route::get('/transferencias/criar', [StockTransferController::class, 'create'])->middleware('permission:transfers.create')->name('transfers.create');
    Route::post('/transferencias', [StockTransferController::class, 'store'])->middleware('permission:transfers.create')->name('transfers.store');
    Route::get('/transferencias/{transfer}', [StockTransferController::class, 'show'])->middleware('permission:transfers.view')->name('transfers.show');
    Route::post('/transferencias/{transfer}/expedir', [StockTransferController::class, 'dispatch'])->middleware('permission:transfers.create')->name('transfers.dispatch');
    Route::post('/transferencias/{transfer}/receber', [StockTransferController::class, 'receive'])->middleware('permission:transfers.receive')->name('transfers.receive');
    Route::post('/transferencias/{transfer}/cancelar', [StockTransferController::class, 'cancel'])->middleware('permission:transfers.cancel')->name('transfers.cancel');
    Route::post('/transferencias/{transfer}/estornar', [StockTransferController::class, 'reverse'])->middleware('permission:transfers.cancel')->name('transfers.reverse');
    Route::resource('politicas-estoque', ProductStockPolicyController::class)
        ->parameters(['politicas-estoque' => 'stockPolicy'])
        ->names('stock-policies')
        ->except(['show', 'destroy'])
        ->middlewareFor(['index'], 'permission:stock_policies.view')
        ->middlewareFor(['create', 'store'], 'permission:stock_policies.manage')
        ->middlewareFor(['edit', 'update'], 'permission:stock_policies.manage,stockPolicy');
    Route::get('/producao-sugerida', [ProductionRequirementController::class, 'index'])->middleware('permission:production_requirements.view')
        ->name('production-requirements.index');
    Route::get('/perdas', [ProductLossController::class, 'index'])->middleware('permission:losses.view')->name('losses.index');
    Route::get('/perdas/criar', [ProductLossController::class, 'create'])->middleware('permission:losses.create')->name('losses.create');
    Route::post('/perdas', [ProductLossController::class, 'store'])->middleware('permission:losses.create')->name('losses.store');
    Route::get('/configuracoes/motivos-perda', [LossReasonController::class, 'index'])->middleware('permission:catalogs.manage')->name('loss-reasons.index');
    Route::post('/configuracoes/motivos-perda', [LossReasonController::class, 'store'])->middleware('permission:catalogs.manage')->name('loss-reasons.store');
    Route::put('/configuracoes/motivos-perda/{lossReason}', [LossReasonController::class, 'update'])->middleware('permission:catalogs.manage')->name('loss-reasons.update');
    Route::get('/relatorios/operacional', [OperationalReportController::class, 'index'])->middleware('permission:reports.view')->name('reports.operational');
    Route::get('/relatorios/custos-margens', CostAnalysisController::class)->middleware('permission:reports.view')->name('costs.index');
    Route::get('/vendas', [ProductSaleController::class, 'index'])->middleware('permission:sales.view')->name('sales.index');
    Route::get('/vendas/criar', [ProductSaleController::class, 'create'])->middleware('permission:sales.create')->name('sales.create');
    Route::post('/vendas', [ProductSaleController::class, 'store'])->middleware('permission:sales.create')->name('sales.store');
    Route::get('/financeiro', [FinanceController::class, 'index'])->middleware('permission:finance.view')->name('finance.index');
    Route::get('/financeiro/contas/criar', [FinanceController::class, 'create'])->middleware('permission:finance.payables.create')->name('finance.create');
    Route::post('/financeiro/contas', [FinanceController::class, 'store'])->middleware('permission:finance.payables.create')->name('finance.store');
    Route::get('/financeiro/contas/{payable}/pagar', [FinanceController::class, 'payment'])->middleware('permission:finance.payments.create')->name('finance.payments.create');
    Route::post('/financeiro/contas/{payable}/pagamentos', [FinanceController::class, 'pay'])->middleware('permission:finance.payments.create')->name('finance.payments.store');
    Route::get('/financeiro/configuracoes', [FinanceController::class, 'settings'])->middleware('permission:finance.accounts.manage')->name('finance.settings');
    Route::post('/financeiro/configuracoes/contas', [FinanceController::class, 'account'])->middleware('permission:finance.accounts.manage')->name('finance.accounts.store');
    Route::post('/financeiro/configuracoes/categorias', [FinanceController::class, 'category'])->middleware('permission:finance.accounts.manage')->name('finance.categories.store');
    Route::post('/financeiro/configuracoes/centros', [FinanceController::class, 'center'])->middleware('permission:finance.accounts.manage')->name('finance.centers.store');
    Route::get('/compras/documentos', [PurchaseDocumentController::class, 'index'])->middleware('permission:purchases.view')->name('purchases.index');
    Route::get('/compras/documentos/criar', [PurchaseDocumentController::class, 'create'])->middleware('permission:purchases.create')->name('purchases.create');
    Route::get('/compras/importacoes', [PurchaseDocumentImportController::class, 'index'])->middleware('permission:purchases.view')->name('purchase-imports.index');
    Route::get('/compras/importacoes/criar', [PurchaseDocumentImportController::class, 'create'])->middleware('permission:purchases.import')->name('purchase-imports.create');
    Route::post('/compras/importacoes', [PurchaseDocumentImportController::class, 'store'])->middleware('permission:purchases.import')->name('purchase-imports.store');
    Route::get('/compras/importacoes/{import}', [PurchaseDocumentImportController::class, 'show'])->middleware('permission:purchases.view')->name('purchase-imports.show');
    Route::put('/compras/importacoes/{import}', [PurchaseDocumentImportController::class, 'update'])->middleware('permission:purchases.approve')->name('purchase-imports.update');
    Route::post('/compras/importacoes/{import}/confirmar', [PurchaseDocumentImportController::class, 'confirm'])->middleware('permission:purchases.approve')->name('purchase-imports.confirm');
    Route::post('/compras/importacoes/{import}/cancelar', [PurchaseDocumentImportController::class, 'cancel'])->middleware('permission:purchases.approve')->name('purchase-imports.cancel');
    Route::get('/compras/documentos/{document}', [PurchaseDocumentController::class, 'show'])->middleware('permission:purchases.view')->name('purchases.show');
    Route::post('/compras/documentos', [PurchaseDocumentController::class, 'store'])->middleware('permission:purchases.create')->name('purchases.store');
    Route::post('/compras/documentos/{document}/receber', [PurchaseDocumentController::class, 'receive'])->middleware('permission:purchases.receive')->name('purchases.receive');
    Route::post('/compras/documentos/{document}/recebimentos', [PurchaseDocumentController::class, 'receivePartial'])->middleware('permission:purchases.receive')->name('purchases.receipts.store');
    Route::post('/compras/documentos/{document}/conta-a-pagar', [PurchaseDocumentController::class, 'payable'])->middleware('permission:finance.payables.create')->name('purchases.payable.store');
    Route::get('/configuracoes/taxas-venda', [PaymentFeeController::class, 'index'])->middleware('permission:payment_fees.view')->name('payment-fees.index');
    Route::get('/configuracoes/taxas-venda/lote', [PaymentFeeController::class, 'batch'])->middleware('permission:payment_fees.manage')->name('payment-fees.batch');
    Route::post('/configuracoes/taxas-venda/previa', [PaymentFeeController::class, 'preview'])->middleware('permission:payment_fees.import')->name('payment-fees.preview');
    Route::get('/configuracoes/taxas-venda/importacoes/{import}', [PaymentFeeController::class, 'showImport'])->middleware('permission:payment_fees.view')->name('payment-fees.imports.show');
    Route::post('/configuracoes/taxas-venda/importacoes/{import}/confirmar', [PaymentFeeController::class, 'confirm'])->middleware('permission:payment_fees.approve_import')->name('payment-fees.imports.confirm');
    Route::post('/configuracoes/taxas-venda/importacoes/{import}/rejeitar', [PaymentFeeController::class, 'reject'])->middleware('permission:payment_fees.approve_import')->name('payment-fees.imports.reject');
    Route::post('/configuracoes/adquirentes', [AcquirerController::class, 'store'])->middleware('permission:acquirers.manage')->name('acquirers.store');
    Route::put('/configuracoes/adquirentes/{acquirer}', [AcquirerController::class, 'update'])->middleware('permission:acquirers.manage')->name('acquirers.update');
    Route::post('/configuracoes/bandeiras', [CardBrandController::class, 'store'])->middleware('permission:card_brands.manage')->name('card-brands.store');
    Route::put('/configuracoes/bandeiras/{cardBrand}', [CardBrandController::class, 'update'])->middleware('permission:card_brands.manage')->name('card-brands.update');
    Route::resource('configuracoes/categorias-produtos', ProductCategoryController::class)
        ->parameters(['categorias-produtos' => 'productCategory'])
        ->names('product-categories')
        ->only(['index', 'store', 'update'])
        ->middleware('permission:product_categories.manage');
    Route::get('/configuracoes/usuarios', [UserAccessController::class, 'index'])
        ->middleware('permission:users.manage')->name('users.index');
    Route::get('/configuracoes/usuarios/{user}/acessos', [UserAccessController::class, 'edit'])
        ->middleware('permission:users.manage')->name('users.access.edit');
    Route::put('/configuracoes/usuarios/{user}/acessos', [UserAccessController::class, 'update'])
        ->middleware('permission:users.manage')->name('users.access.update');
    Route::put('/configuracoes/usuarios/{user}/dashboard', [DashboardUserVisibilityController::class, 'update'])
        ->middleware('permission:dashboard.permissions.manage')->name('users.dashboard.update');
    Route::delete('/configuracoes/usuarios/{user}/dashboard', [DashboardUserVisibilityController::class, 'reset'])
        ->middleware('permission:dashboard.permissions.manage')->name('users.dashboard.reset');
    Route::get('/configuracoes/preparacao-operacao', OperationalReadinessController::class)
        ->middleware('permission:operations.readiness.view')->name('operations.readiness');
    Route::resource('insumos', IngredientController::class)
        ->parameters(['insumos' => 'ingredient'])
        ->names('ingredients')
        ->except('destroy')
        ->middlewareFor(['index', 'show'], 'permission:ingredients.view')
        ->middlewareFor(['create', 'store'], 'permission:ingredients.create')
        ->middlewareFor(['edit', 'update'], 'permission:ingredients.update');
    Route::post('/insumos/{ingredient}/precos', [IngredientPriceController::class, 'store'])
        ->middleware('permission:ingredient_prices.update')->name('ingredients.prices.store');
    Route::get('/estoque-insumos', [IngredientStockController::class, 'index'])->middleware('permission:ingredient_stock.view')->name('ingredient-stock.index');
    Route::get('/estoque-insumos/{ingredient}', [IngredientStockController::class, 'show'])->middleware('permission:ingredient_stock.view')->name('ingredient-stock.show');
    Route::post('/estoque-insumos/perdas', [IngredientStockController::class, 'loss'])->middleware('permission:ingredient_losses.create')->name('ingredient-stock.losses.store');
    Route::post('/estoque-insumos/ajustes', [IngredientStockController::class, 'adjustment'])->middleware('permission:ingredient_stock.adjust')->name('ingredient-stock.adjustments.store');
    Route::resource('configuracoes/categorias-insumos', IngredientCategoryController::class)
        ->parameters(['categorias-insumos' => 'ingredientCategory'])
        ->names('ingredient-categories')
        ->except(['show', 'destroy'])->middleware('permission:catalogs.manage');

    Route::resource('equipamentos', ProductionEquipmentController::class)
        ->parameters(['equipamentos' => 'equipment'])
        ->names('equipment')
        ->except('destroy')->middleware('permission:catalogs.manage');
    Route::post('/equipamentos/{equipment}/queimadores', [EquipmentBurnerController::class, 'store'])
        ->middleware('permission:catalogs.manage')->name('equipment.burners.store');
    Route::get('/equipamentos/{equipment}/queimadores/{burner}/editar', [EquipmentBurnerController::class, 'edit'])
        ->middleware('permission:catalogs.manage')->name('equipment.burners.edit');
    Route::put('/equipamentos/{equipment}/queimadores/{burner}', [EquipmentBurnerController::class, 'update'])
        ->middleware('permission:catalogs.manage')->name('equipment.burners.update');

    Route::resource('glp', GlpProductController::class)
        ->parameters(['glp' => 'glpProduct'])
        ->names('glp-products')
        ->except('destroy')->middleware('permission:catalogs.manage');
    Route::post('/glp/{glpProduct}/precos', [GlpPriceController::class, 'store'])
        ->middleware('permission:catalogs.manage')->name('glp-products.prices.store');

    Route::resource('preparos', PreparationController::class)
        ->parameters(['preparos' => 'preparation'])
        ->names('preparations')
        ->except('destroy')
        ->middlewareFor(['index', 'show'], 'permission:preparations.view')
        ->middlewareFor(['create', 'store'], 'permission:preparations.create')
        ->middlewareFor(['edit', 'update'], 'permission:preparations.update');
    Route::post('/preparos/{preparation}/ingredientes', [PreparationIngredientController::class, 'store'])
        ->middleware('permission:preparations.update')->name('preparations.ingredients.store');
    Route::delete('/preparos/{preparation}/ingredientes/{preparationIngredient}', [PreparationIngredientController::class, 'destroy'])
        ->middleware('permission:preparations.update')->name('preparations.ingredients.destroy');
    Route::post('/preparos/{preparation}/energia', [PreparationEnergyUsageController::class, 'store'])
        ->middleware('permission:preparations.update')->name('preparations.energy-usages.store');
    Route::get('/preparos/{preparation}/energia/{energyUsage}/editar', [PreparationEnergyUsageController::class, 'edit'])
        ->middleware('permission:preparations.update')->name('preparations.energy-usages.edit');
    Route::put('/preparos/{preparation}/energia/{energyUsage}', [PreparationEnergyUsageController::class, 'update'])
        ->middleware('permission:preparations.update')->name('preparations.energy-usages.update');
    Route::delete('/preparos/{preparation}/energia/{energyUsage}', [PreparationEnergyUsageController::class, 'destroy'])
        ->middleware('permission:preparations.update')->name('preparations.energy-usages.destroy');
    Route::post('/preparos/{preparation}/custos-adicionais', [PreparationAdditionalCostController::class, 'store'])
        ->middleware('permission:preparations.update')->name('preparations.additional-costs.store');
    Route::delete('/preparos/{preparation}/custos-adicionais/{additionalCost}', [PreparationAdditionalCostController::class, 'destroy'])
        ->middleware('permission:preparations.update')->name('preparations.additional-costs.destroy');

    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
