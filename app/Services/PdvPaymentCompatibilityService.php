<?php

namespace App\Services;

use App\Enums\ProductSalePaymentMethod;

class PdvPaymentCompatibilityService
{
    /** @return array{supported:bool,method:?string,label:string,requires_acquirer:bool,requires_brand:bool,requires_rate:bool,reason:?string} */
    public function forExternal(?string $description, ?string $type): array
    {
        $value = $this->normalize(trim((string) $description.' '.(string) $type));

        return match (true) {
            str_contains($value, 'pix') => $this->supported(ProductSalePaymentMethod::Pix),
            str_contains($value, 'dinheiro'), str_contains($value, 'cash') => $this->supported(ProductSalePaymentMethod::Cash),
            str_contains($value, 'debito'), str_contains($value, 'debit') => $this->supported(ProductSalePaymentMethod::Debit),
            str_contains($value, 'credito'), str_contains($value, 'credit') => $this->supported(ProductSalePaymentMethod::Credit),
            default => $this->unsupported('A forma externa não possui equivalência financeira oficial reconhecida.'),
        };
    }

    public function supportsMethod(?string $method): bool
    {
        return in_array($method, ProductSalePaymentMethod::values(), true);
    }

    /** @return array{supported:bool,method:string,label:string,requires_acquirer:bool,requires_brand:bool,requires_rate:bool,reason:null} */
    private function supported(ProductSalePaymentMethod $method): array
    {
        $card = $method->requiresCardConfiguration();

        return ['supported' => true, 'method' => $method->value, 'label' => $method->label(), 'requires_acquirer' => $card, 'requires_brand' => false, 'requires_rate' => $card, 'reason' => null];
    }

    /** @return array{supported:bool,method:null,label:string,requires_acquirer:bool,requires_brand:bool,requires_rate:bool,reason:string} */
    private function unsupported(string $reason): array
    {
        return ['supported' => false, 'method' => null, 'label' => 'Não suportado', 'requires_acquirer' => false, 'requires_brand' => false, 'requires_rate' => false, 'reason' => $reason];
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value);
    }
}
