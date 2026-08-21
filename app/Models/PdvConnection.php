<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PdvConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['encrypted_credentials'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'configuration' => 'array',
            'encrypted_credentials' => 'encrypted:array',
            'last_attempt_at' => 'datetime',
            'last_success_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_sale_imported_at' => 'datetime',
        ];
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function locationMappings(): HasMany
    {
        return $this->hasMany(PdvLocationMapping::class);
    }

    public function productMappings(): HasMany
    {
        return $this->hasMany(PdvProductMapping::class);
    }

    public function paymentMappings(): HasMany
    {
        return $this->hasMany(PdvPaymentMethodMapping::class);
    }

    public function inboundEvents(): HasMany
    {
        return $this->hasMany(PdvInboundEvent::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PdvOrder::class);
    }

    public function saleOrders(): HasMany
    {
        return $this->hasMany(ProductSaleOrder::class);
    }

    public function integrationEvents(): HasMany
    {
        return $this->hasMany(PdvIntegrationEvent::class);
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(PdvSyncCheckpoint::class);
    }
}
