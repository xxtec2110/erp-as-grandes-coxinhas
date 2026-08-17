<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseDocumentImport extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'date',
            'gross_amount' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'freight_amount' => 'decimal:2',
            'other_charges_amount' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'confidence' => 'decimal:6',
            'field_confidences' => 'array',
            'warnings' => 'array',
            'missing_fields' => 'array',
            'ambiguous_fields' => 'array',
            'interpretation' => 'array',
            'expires_at' => 'datetime',
            'confirmed_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseDocumentImportItem::class)->orderBy('line_number');
    }

    public function attachments(): BelongsToMany
    {
        return $this->belongsToMany(AgentAttachment::class, 'purchase_document_import_attachments')
            ->withPivot('page_order')->withTimestamps()->orderByPivot('page_order');
    }

    public function confirmedDocument(): BelongsTo
    {
        return $this->belongsTo(PurchaseDocument::class, 'confirmed_purchase_document_id');
    }
}
