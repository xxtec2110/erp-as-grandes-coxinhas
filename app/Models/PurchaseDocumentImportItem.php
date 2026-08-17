<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseDocumentImportItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:6', 'package_quantity' => 'decimal:6', 'package_size' => 'decimal:6',
            'unit_price_original' => 'decimal:6', 'package_price' => 'decimal:4',
            'gross_amount' => 'decimal:2', 'discount_amount' => 'decimal:2', 'freight_amount' => 'decimal:2',
            'other_charges_amount' => 'decimal:2', 'net_amount' => 'decimal:2',
            'normalized_quantity' => 'decimal:6', 'normalized_unit_cost' => 'decimal:8',
            'confidence' => 'decimal:6', 'warnings' => 'array',
        ];
    }

    public function import(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocumentImport::class, 'purchase_document_import_id');
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
