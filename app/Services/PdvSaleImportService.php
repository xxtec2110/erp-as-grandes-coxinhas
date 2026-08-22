<?php

namespace App\Services;

use App\Models\PdvConnection;
use App\Models\PdvInboundEvent;
use App\Models\PdvLocationMapping;
use App\Models\PdvPaymentMethodMapping;
use App\Models\PdvProductMapping;
use App\Models\ProductSale;
use App\Models\User;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\IntegrationNotConfiguredException;
use Illuminate\Support\Facades\DB;

class PdvSaleImportService
{
    public function __construct(private ProductSaleService $sales, private PdvIntegrationEventService $events, private PdvConnectionAccessService $access) {}

    /** @return array{status:string,sales:array<int,ProductSale>,missing:array<int,string>} */
    public function import(PdvConnection $connection, ExternalSaleData $data, User $user, ?PdvInboundEvent $inbound = null): array
    {
        if ($connection->provider === 'grandchef') {
            throw new IntegrationNotConfiguredException('GrandChef deve passar pelo staging oficial; a importação legada direta é proibida.');
        }

        if (! config('pdv.import_enabled', false)) {
            throw new IntegrationNotConfiguredException('A importação operacional de PDV está desabilitada. Use staging e confirmação humana.');
        }

        return DB::transaction(function () use ($connection, $data, $user, $inbound): array {
            $connectionLocation = $this->access->assertOperationalScope($connection);
            $this->access->authorizeConnection($user, $connection);
            $location = PdvLocationMapping::query()->whereBelongsTo($connection, 'connection')->where('external_location_id', $data->externalLocationId)->where('status', 'confirmed')->first()?->location;
            $missing = $location ? [] : ['location:'.$data->externalLocationId];
            if ($location !== null && $location->id !== $connectionLocation->id) {
                $missing[] = 'location_scope_mismatch';
                $location = null;
            }
            $mapped = [];
            foreach ($data->items as $item) {
                $mapping = PdvProductMapping::query()->whereBelongsTo($connection, 'connection')->where('external_product_id', $item->externalProductId)->where('status', 'confirmed')->first();
                if (! $mapping?->product_id) {
                    $missing[] = 'product:'.($item->externalProductId ?? $item->name);
                } else {
                    $mapped[] = [$item, $mapping->product_id];
                }
            }
            $payment = $data->payments[0] ?? null;
            $paymentMapping = $payment?->methodCode ? PdvPaymentMethodMapping::query()->whereBelongsTo($connection, 'connection')->where('external_method_code', $payment->methodCode)->where('status', 'confirmed')->first() : null;
            if ($payment !== null && $paymentMapping === null) {
                $missing[] = 'payment:'.($payment->methodCode ?? $payment->methodName);
            }
            if ($missing !== []) {
                $inbound?->update(['status' => 'waiting_mapping', 'error_code' => 'waiting_mapping', 'error_message' => implode(', ', $missing), 'attempts' => DB::raw('attempts + 1')]);
                $connection->increment('events_waiting_mapping_count');
                $this->events->record('mapping_pending', $connection, $inbound, $user, 'waiting_mapping', ['missing' => $missing]);

                return ['status' => 'waiting_mapping', 'sales' => [], 'missing' => $missing];
            }
            $created = [];
            if (in_array($data->status, ['cancelled', 'voided', 'refunded'], true)) {
                foreach (ProductSale::query()->whereBelongsTo($connection, 'pdvConnection')->where('external_sale_id', $data->externalSaleId)->get() as $sale) {
                    $created[] = $this->sales->reverse($sale, $user, 'Cancelamento recebido do PDV.');
                }
                $inbound?->update(['status' => 'cancelled', 'processed_at' => now(), 'attempts' => DB::raw('attempts + 1')]);
                $this->events->record('sale_cancelled', $connection, $inbound, $user, 'cancelled');

                return ['status' => 'cancelled', 'sales' => $created, 'missing' => []];
            }
            foreach ($mapped as [$item, $productId]) {
                $created[] = $this->sales->record(['product_id' => $productId, 'location_id' => $location->id, 'quantity' => $item->quantity, 'unit_price' => $item->unitPrice, 'operation_date' => $data->closedAt->setTimezone(config('app.timezone'))->toDateString(), 'payment_method' => $paymentMapping?->payment_method ?? 'cash', 'acquirer_id' => $paymentMapping?->acquirer_id, 'card_brand_id' => $paymentMapping?->card_brand_id, 'installments' => $payment?->installments, 'idempotency_key' => "pdv:{$connection->id}:{$data->externalSaleId}:{$item->externalItemId}", 'pdv_connection_id' => $connection->id, 'external_sale_id' => $data->externalSaleId, 'external_item_id' => $item->externalItemId, 'external_status' => $data->status, 'external_updated_at' => $data->updatedAt, 'notes' => 'Venda importada do PDV.'], $user, 'pdv');
            }
            $inbound?->update(['status' => 'imported', 'processed_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'error_code' => null, 'error_message' => null]);
            $lag = max(0, now()->diffInSeconds($data->updatedAt, false) * -1);
            $connection->update(['last_sale_imported_at' => now(), 'sync_lag_seconds' => $lag]);
            $connection->increment('sales_imported_count');
            $this->events->record('sale_imported', $connection, $inbound, $user, 'imported', ['external_sale_id' => $data->externalSaleId, 'items' => count($created)], lagSeconds: $lag);

            return ['status' => 'imported', 'sales' => $created, 'missing' => []];
        });
    }
}
