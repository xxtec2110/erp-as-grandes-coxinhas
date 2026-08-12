<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class AuthorizationSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            'agent.text.use' => 'Usar texto no Agente', 'agent.image.use' => 'Enviar imagens ao Agente', 'agent.document.use' => 'Enviar documentos ao Agente', 'agent.audio.use' => 'Enviar áudio ao Agente', 'agent.free_chat.use' => 'Usar conversa livre com IA',
            'agent.whatsapp.manage_connection' => 'Gerenciar conexão do WhatsApp do Agente', 'agent.operations.undo' => 'Solicitar reversão pelo Agente',
            'finance.payments.cancel' => 'Cancelar pagamentos', 'purchases.cancel' => 'Cancelar compras', 'production.cancel' => 'Cancelar produção', 'losses.cancel' => 'Cancelar perdas', 'transfers.cancel' => 'Cancelar transferências',
            'finance.view' => 'Acessar financeiro', 'finance.payables.view' => 'Consultar contas a pagar', 'finance.payables.create' => 'Criar contas a pagar', 'finance.payables.update' => 'Alterar contas a pagar', 'finance.payables.cancel' => 'Cancelar contas a pagar', 'finance.payments.view' => 'Consultar pagamentos', 'finance.payments.create' => 'Registrar pagamentos', 'finance.payments.update' => 'Alterar pagamentos', 'finance.accounts.view' => 'Consultar contas financeiras', 'finance.accounts.manage' => 'Gerenciar contas financeiras', 'finance.reports.view' => 'Consultar relatórios financeiros', 'purchases.view' => 'Consultar compras', 'purchases.create' => 'Cadastrar documentos de compra', 'purchases.import' => 'Importar documentos de compra', 'purchases.approve' => 'Aprovar compras importadas', 'suppliers.view' => 'Consultar fornecedores', 'suppliers.manage' => 'Gerenciar fornecedores', 'ingredient_prices.update' => 'Atualizar preços de insumos',
            'payment_fees.view' => 'Consultar taxas de venda', 'payment_fees.manage' => 'Alterar taxas de venda', 'payment_fees.import' => 'Preparar importação de taxas', 'payment_fees.approve_import' => 'Aprovar importação de taxas', 'acquirers.view' => 'Consultar adquirentes', 'acquirers.manage' => 'Gerenciar adquirentes', 'card_brands.view' => 'Consultar bandeiras', 'card_brands.manage' => 'Gerenciar bandeiras',
            'users.manage' => 'Gerenciar usuários e acessos', 'stock.view' => 'Consultar estoque', 'stock.view_other_locations' => 'Consultar estoque de outras unidades', 'stock.adjust' => 'Registrar ajuste', 'production.view' => 'Consultar produção', 'production.create' => 'Registrar produção', 'transfers.view' => 'Consultar transferências', 'transfers.create' => 'Registrar transferência', 'transfers.receive' => 'Confirmar recebimento', 'losses.view' => 'Consultar perdas', 'losses.create' => 'Registrar perda', 'stock_policies.view' => 'Consultar política de estoque', 'stock_policies.manage' => 'Alterar política de estoque', 'production_requirements.view' => 'Consultar produção sugerida', 'reports.view' => 'Consultar relatórios', 'products.view' => 'Consultar produtos', 'products.create' => 'Cadastrar produto', 'products.update' => 'Editar produto', 'product_categories.manage' => 'Gerenciar categorias de produtos', 'prices.manage' => 'Alterar preço', 'sales.view' => 'Consultar vendas', 'sales.create' => 'Registrar venda/saída comercial', 'catalogs.manage' => 'Gerenciar cadastros técnicos',
            'locations.view' => 'Consultar unidades', 'locations.create' => 'Cadastrar unidades', 'locations.update' => 'Editar unidades',
            'ingredients.view' => 'Consultar insumos', 'ingredients.create' => 'Cadastrar insumos', 'ingredients.update' => 'Editar insumos',
            'preparations.view' => 'Consultar preparos', 'preparations.create' => 'Cadastrar preparos', 'preparations.update' => 'Editar preparos',
        ];
        foreach ($permissions as $name => $label) {
            Permission::query()->updateOrCreate(['name' => $name], ['label' => $label, 'group' => strtok($name, '.')]);
        }
        $roles = ['administrator' => 'Administrador', 'management' => 'Sócio/Gerência', 'production' => 'Produção', 'store' => 'Unidade/Loja', 'operator' => 'Operador'];
        foreach ($roles as $name => $label) {
            Role::query()->updateOrCreate(['name' => $name], ['label' => $label]);
        }
        Role::query()->where('name', 'administrator')->firstOrFail()->permissions()->sync(Permission::query()->pluck('id'));
    }
}
