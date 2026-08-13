<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseReceiptItem extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['quantity_received' => 'decimal:6'];
    }

    public function documentItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocumentItem::class, 'purchase_document_item_id');
    }
}
