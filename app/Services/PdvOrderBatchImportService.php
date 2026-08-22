<?php

namespace App\Services;

use App\Models\PdvOrder;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Throwable;

class PdvOrderBatchImportService
{
    public function __construct(private PdvOrderImportService $imports) {}

    /** @param iterable<int,PdvOrder> $orders @return array<int,array<string,mixed>> */
    public function execute(iterable $orders, User $user): array
    {
        $orders = $orders instanceof Collection ? $orders->values() : collect($orders)->values();
        if (config('pdv.first_import_single_order', true) && $orders->count() > 1) {
            throw new DomainException('O primeiro go-live permite importar somente um pedido por operação.');
        }

        $results = [];
        foreach ($orders as $order) {
            try {
                $result = $this->imports->execute($order, $user);
                $results[] = ['pdv_order_id' => $order->id, 'status' => $result['status'], 'product_sale_order_id' => $result['order']->id];
            } catch (Throwable $exception) {
                $results[] = ['pdv_order_id' => $order->id, 'status' => 'failed', 'error' => class_basename($exception)];
            }
        }

        return $results;
    }
}
