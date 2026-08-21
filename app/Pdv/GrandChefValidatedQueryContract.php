<?php

namespace App\Pdv;

use App\Models\PdvConnection;
use App\Pdv\Data\ExternalSaleData;
use App\Pdv\Data\ExternalSaleItemData;
use App\Pdv\Data\ExternalSalePaymentData;
use App\Pdv\Data\GrandChefGraphqlRequest;
use App\Pdv\Data\PdvPage;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;

class GrandChefValidatedQueryContract implements GrandChefQueryContract
{
    private const PAGE_SIZE = 10;

    public function available(): bool
    {
        return true;
    }

    public function connectionRequest(): GrandChefGraphqlRequest
    {
        return new GrandChefGraphqlRequest(<<<'GRAPHQL'
            query GrandChefConnection {
              pedidos(limit: 1, page: 1) {
                total
                per_page
                current_page
                last_page
                has_more_pages
              }
            }
            GRAPHQL, operationName: 'GrandChefConnection');
    }

    public function salesRequest(CarbonImmutable $from, CarbonImmutable $to, ?array $cursor = null): GrandChefGraphqlRequest
    {
        $timezone = config('app.timezone', 'America/Sao_Paulo');

        return new GrandChefGraphqlRequest(<<<'GRAPHQL'
            query GrandChefSales($filter: PedidoFilter!, $limit: Int!, $page: Int!) {
              pedidos(filter: $filter, limit: $limit, page: $page) {
                total
                per_page
                current_page
                from
                to
                last_page
                has_more_pages
                data {
                  id
                  codigo
                  estado
                  subtotal
                  descontos
                  total
                  pago
                  troco
                  quantidade
                  data_criacao
                  data_conclusao
                }
              }
            }
            GRAPHQL, [
            'filter' => [
                'estado' => ['eq' => 'concluido'],
                'data_conclusao' => [
                    'from' => $from->setTimezone($timezone)->startOfDay()->toIso8601String(),
                    'to' => $to->setTimezone($timezone)->endOfDay()->toIso8601String(),
                ],
            ],
            'limit' => self::PAGE_SIZE,
            'page' => max(1, (int) ($cursor['page'] ?? 1)),
        ], 'GrandChefSales');
    }

    public function saleRequest(string $externalSaleId): GrandChefGraphqlRequest
    {
        return new GrandChefGraphqlRequest(<<<'GRAPHQL'
            query GrandChefSale($id: ID!) {
              pedido(id: $id) {
                id
                codigo
                estado
                subtotal
                descontos
                total
                pago
                troco
                quantidade
                data_criacao
                data_conclusao
                itens {
                  id
                  produto_id
                  descricao
                  preco
                  quantidade
                  subtotal
                  total
                  estado
                  cancelado
                  produto {
                    id
                    codigo
                    descricao
                  }
                }
                pagamentos {
                  id
                  forma_id
                  total
                  taxas
                  valor
                  numero_parcela
                  parcelas
                  estado
                  tipo
                  data_pagamento
                  data_lancamento
                  forma {
                    id
                    descricao
                    tipo
                  }
                }
              }
            }
            GRAPHQL, ['id' => $externalSaleId], 'GrandChefSale');
    }

    public function connectionResponseIsValid(array $data): bool
    {
        $page = data_get($data, 'pedidos');

        return is_array($page)
            && is_numeric($page['total'] ?? null)
            && is_numeric($page['current_page'] ?? null)
            && is_numeric($page['last_page'] ?? null)
            && array_key_exists('has_more_pages', $page);
    }

    public function normalizeSales(PdvConnection $connection, array $data): PdvPage
    {
        $page = data_get($data, 'pedidos');
        if (! is_array($page) || ! is_array($page['data'] ?? null)) {
            throw $this->invalid('A listagem GrandChef não retornou a paginação esperada.');
        }

        $currentPage = $this->integer($page['current_page'] ?? null, 'current_page');
        $lastPage = $this->integer($page['last_page'] ?? null, 'last_page');
        $hasMore = filter_var($page['has_more_pages'] ?? false, FILTER_VALIDATE_BOOL);
        $items = [];

        foreach ($page['data'] as $order) {
            if (! is_array($order)) {
                throw $this->invalid('A listagem GrandChef retornou um pedido inválido.');
            }

            $items[] = $this->sale($connection, $order, false);
        }

        return new PdvPage(
            $items,
            $hasMore && $currentPage < $lastPage ? ['page' => $currentPage + 1] : null,
            $this->integer($page['total'] ?? null, 'total'),
            [
                'requires_detail_fetch' => true,
                'per_page' => $this->integer($page['per_page'] ?? null, 'per_page'),
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'from' => $page['from'] ?? null,
                'to' => $page['to'] ?? null,
            ],
        );
    }

    public function normalizeSale(PdvConnection $connection, array $data): ?ExternalSaleData
    {
        $order = data_get($data, 'pedido');
        if ($order === null) {
            return null;
        }
        if (! is_array($order)) {
            throw $this->invalid('O detalhe GrandChef retornou um pedido inválido.');
        }

        return $this->sale($connection, $order, true);
    }

    private function sale(PdvConnection $connection, array $order, bool $withDetails): ExternalSaleData
    {
        $id = $this->requiredString($order['id'] ?? null, 'id do pedido');
        $closedAt = $this->date($order['data_conclusao'] ?? null, 'data_conclusao');
        $openedAt = filled($order['data_criacao'] ?? null)
            ? $this->date($order['data_criacao'], 'data_criacao')
            : null;

        return new ExternalSaleData(
            'grandchef',
            $id,
            $this->nullableString($order['codigo'] ?? null),
            (string) $connection->location_id,
            $this->requiredString($order['estado'] ?? null, 'estado do pedido'),
            $openedAt,
            $closedAt,
            $closedAt,
            $this->requiredDecimal($order['subtotal'] ?? null, 'subtotal'),
            $this->decimalOrZero($order['descontos'] ?? null),
            '0',
            '0',
            $this->requiredDecimal($order['total'] ?? null, 'total'),
            $withDetails ? $this->items($order['itens'] ?? []) : [],
            $withDetails ? $this->payments($order['pagamentos'] ?? []) : [],
            metadata: [
                'details_complete' => $withDetails,
                'reported_quantity' => $this->nullableDecimal($order['quantidade'] ?? null),
            ],
            paidAmount: $this->nullableDecimal($order['pago'] ?? null),
            changeAmount: $this->nullableDecimal($order['troco'] ?? null),
        );
    }

    /** @return array<int, ExternalSaleItemData> */
    private function items(mixed $rawItems): array
    {
        if (! is_array($rawItems)) {
            throw $this->invalid('O detalhe GrandChef retornou itens inválidos.');
        }

        $items = [];
        foreach ($rawItems as $rawItem) {
            if (! is_array($rawItem)) {
                throw $this->invalid('O detalhe GrandChef retornou um item inválido.');
            }

            $subtotal = $this->requiredDecimal($rawItem['subtotal'] ?? null, 'subtotal do item');
            $total = $this->requiredDecimal($rawItem['total'] ?? null, 'total do item');
            $discount = BigDecimal::of($subtotal)->minus($total);
            if ($discount->isNegative()) {
                $discount = BigDecimal::zero();
            }
            $product = is_array($rawItem['produto'] ?? null) ? $rawItem['produto'] : [];

            $items[] = new ExternalSaleItemData(
                $this->requiredString($rawItem['id'] ?? null, 'id do item'),
                $this->nullableString($product['id'] ?? $rawItem['produto_id'] ?? null),
                $this->nullableString($product['codigo'] ?? null),
                $this->requiredString($product['descricao'] ?? $rawItem['descricao'] ?? null, 'descrição do item'),
                $this->requiredDecimal($rawItem['quantidade'] ?? null, 'quantidade do item'),
                $this->requiredDecimal($rawItem['preco'] ?? null, 'preço do item'),
                (string) $discount,
                $total,
                notes: null,
                subtotal: $subtotal,
                externalStatus: $this->nullableString($rawItem['estado'] ?? null),
                cancelled: filter_var($rawItem['cancelado'] ?? false, FILTER_VALIDATE_BOOL) || ($rawItem['estado'] ?? null) === 'cancelado',
            );
        }

        return $items;
    }

    /** @return array<int, ExternalSalePaymentData> */
    private function payments(mixed $rawPayments): array
    {
        if (! is_array($rawPayments)) {
            throw $this->invalid('O detalhe GrandChef retornou pagamentos inválidos.');
        }

        $payments = [];
        foreach ($rawPayments as $rawPayment) {
            if (! is_array($rawPayment)) {
                throw $this->invalid('O detalhe GrandChef retornou um pagamento inválido.');
            }
            $method = is_array($rawPayment['forma'] ?? null) ? $rawPayment['forma'] : [];

            $payments[] = new ExternalSalePaymentData(
                $this->requiredString($rawPayment['id'] ?? null, 'id do pagamento'),
                $this->nullableString($method['id'] ?? $rawPayment['forma_id'] ?? null),
                $this->nullableString($method['descricao'] ?? null),
                null,
                $this->requiredDecimal($rawPayment['valor'] ?? null, 'valor do pagamento'),
                isset($rawPayment['parcelas']) ? (int) $rawPayment['parcelas'] : null,
                $this->nullableString($rawPayment['estado'] ?? null),
                $this->nullableString($method['tipo'] ?? $rawPayment['tipo'] ?? null),
                metadata: [
                    'total' => $this->nullableDecimal($rawPayment['total'] ?? null),
                    'fees' => $this->nullableDecimal($rawPayment['taxas'] ?? null),
                    'installment_number' => isset($rawPayment['numero_parcela']) ? (int) $rawPayment['numero_parcela'] : null,
                    'paid_at' => $this->nullableString($rawPayment['data_pagamento'] ?? null),
                    'recorded_at' => $this->nullableString($rawPayment['data_lancamento'] ?? null),
                ],
                externalTotal: $this->nullableDecimal($rawPayment['total'] ?? null),
                fees: $this->nullableDecimal($rawPayment['taxas'] ?? null),
                installmentNumber: isset($rawPayment['numero_parcela']) ? (int) $rawPayment['numero_parcela'] : null,
                paidAt: $this->nullableString($rawPayment['data_pagamento'] ?? null),
                postedAt: $this->nullableString($rawPayment['data_lancamento'] ?? null),
            );
        }

        return $payments;
    }

    private function date(mixed $value, string $field): CarbonImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            throw $this->invalid("O GrandChef não informou {$field}.");
        }

        try {
            return CarbonImmutable::parse($value, config('app.timezone', 'America/Sao_Paulo'));
        } catch (\Throwable) {
            throw $this->invalid("O GrandChef informou {$field} inválida.");
        }
    }

    private function integer(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw $this->invalid("O GrandChef informou {$field} inválido.");
        }

        return (int) $value;
    }

    private function requiredDecimal(mixed $value, string $field): string
    {
        $decimal = $this->nullableDecimal($value);
        if ($decimal === null) {
            throw $this->invalid("O GrandChef não informou {$field} válido.");
        }

        return $decimal;
    }

    private function decimalOrZero(mixed $value): string
    {
        return $this->nullableDecimal($value) ?? '0';
    }

    private function nullableDecimal(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
            return null;
        }
        if (is_float($value) && ! is_finite($value)) {
            return null;
        }

        try {
            return (string) BigDecimal::of((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function requiredString(mixed $value, string $field): string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            throw $this->invalid("O GrandChef não informou {$field}.");
        }

        return $value;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null || (! is_scalar($value) && ! $value instanceof \Stringable)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function invalid(string $message): GrandChefRequestException
    {
        return new GrandChefRequestException('invalid_response', $message);
    }
}
