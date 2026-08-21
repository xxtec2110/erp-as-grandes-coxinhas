<?php

namespace Tests\Support;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\Data\ExternalSalePaymentData;
use App\Pdv\Data\GrandChefGraphqlRequest;
use App\Pdv\Data\PdvPage;
use App\Pdv\GrandChefQueryContract;
use Carbon\CarbonImmutable;

class FakeGrandChefQueryContract implements GrandChefQueryContract
{
    public function available(): bool
    {
        return true;
    }

    public function connectionRequest(): GrandChefGraphqlRequest
    {
        return new GrandChefGraphqlRequest('TEST_ONLY_CONNECTION_REQUEST', operationName: 'TestOnlyConnection');
    }

    public function salesRequest(CarbonImmutable $from, CarbonImmutable $to, ?array $cursor = null): GrandChefGraphqlRequest
    {
        return new GrandChefGraphqlRequest('TEST_ONLY_SALES_REQUEST', ['from' => $from->toIso8601String(), 'to' => $to->toIso8601String(), 'cursor' => $cursor], 'TestOnlySales');
    }

    public function saleRequest(string $externalSaleId): GrandChefGraphqlRequest
    {
        return new GrandChefGraphqlRequest('TEST_ONLY_SALE_REQUEST', ['id' => $externalSaleId], 'TestOnlySale');
    }

    public function connectionResponseIsValid(array $data): bool
    {
        return data_get($data, 'fixture.connection') === 'accepted';
    }

    public function normalizeSales(PdvConnection $connection, array $data): PdvPage
    {
        return new PdvPage(
            collect(data_get($data, 'fixture.orders', []))->map(fn (array $order): ExternalSaleData => $this->sale($connection, $order))->all(),
            data_get($data, 'fixture.next_cursor'),
            data_get($data, 'fixture.total'),
        );
    }

    public function normalizeSale(PdvConnection $connection, array $data): ?ExternalSaleData
    {
        $order = data_get($data, 'fixture.order');

        return is_array($order) ? $this->sale($connection, $order) : null;
    }

    private function sale(PdvConnection $connection, array $order): ExternalSaleData
    {
        $timezone = 'America/Sao_Paulo';
        $closedAt = CarbonImmutable::parse($order['closed_at'], $timezone);

        return new ExternalSaleData(
            'grandchef',
            (string) $order['id'],
            isset($order['code']) ? (string) $order['code'] : null,
            (string) ($order['location_id'] ?? $connection->location_id),
            (string) ($order['status'] ?? 'closed'),
            isset($order['opened_at']) ? CarbonImmutable::parse($order['opened_at'], $timezone) : null,
            $closedAt,
            CarbonImmutable::parse($order['updated_at'] ?? $order['closed_at'], $timezone),
            (string) $order['gross_amount'],
            (string) ($order['discount_amount'] ?? '0'),
            (string) ($order['service_charge_amount'] ?? '0'),
            (string) ($order['delivery_amount'] ?? '0'),
            (string) $order['net_amount'],
            collect($order['items'])->map(fn (array $item): ExternalSaleItemData => new ExternalSaleItemData(
                (string) $item['id'],
                isset($item['product_id']) ? (string) $item['product_id'] : null,
                isset($item['code']) ? (string) $item['code'] : null,
                (string) $item['name'],
                (string) $item['quantity'],
                (string) $item['unit_price'],
                (string) ($item['discount'] ?? '0'),
                (string) $item['total'],
                subtotal: isset($item['subtotal']) ? (string) $item['subtotal'] : null,
                externalStatus: $item['status'] ?? null,
                cancelled: (bool) ($item['cancelled'] ?? false),
            ))->all(),
            collect($order['payments'] ?? [])->map(fn (array $payment): ExternalSalePaymentData => new ExternalSalePaymentData(
                (string) $payment['id'],
                isset($payment['method_code']) ? (string) $payment['method_code'] : null,
                isset($payment['method_name']) ? (string) $payment['method_name'] : null,
                isset($payment['brand']) ? (string) $payment['brand'] : null,
                (string) $payment['amount'],
                $payment['installments'] ?? null,
                $payment['status'] ?? null,
                $payment['type'] ?? null,
                isset($payment['change_amount']) ? (string) $payment['change_amount'] : null,
                externalTotal: isset($payment['external_total']) ? (string) $payment['external_total'] : null,
                fees: isset($payment['fees']) ? (string) $payment['fees'] : null,
                installmentNumber: $payment['installment_number'] ?? null,
                paidAt: $payment['paid_at'] ?? null,
                postedAt: $payment['posted_at'] ?? null,
            ))->all(),
            metadata: ['fixture' => true],
            paidAmount: isset($order['paid_amount']) ? (string) $order['paid_amount'] : null,
            changeAmount: isset($order['change_amount']) ? (string) $order['change_amount'] : null,
        );
    }
}
