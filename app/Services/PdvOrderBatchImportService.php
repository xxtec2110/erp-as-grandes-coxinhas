<?php

namespace App\Services;

use App\Models\PdvOrder;
use App\Models\User;
use Throwable;

class PdvOrderBatchImportService
{
    public function __construct(private PdvOrderImportService $imports) {}

    /** @param iterable<int,PdvOrder> $orders @return array<int,array<string,mixed>> */
    public function execute(iterable $orders, User $user): array
    {
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
