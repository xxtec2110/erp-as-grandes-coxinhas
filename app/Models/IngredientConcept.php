<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientConcept extends Model
{
    protected $guarded = ['id', 'code', 'name', 'active', 'is_protected'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_protected' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $concept): void {
            if ($concept->getOriginal('is_protected') && $concept->isDirty(['code', 'name', 'active', 'is_protected'])) {
                throw new DomainException('Um conceito protegido de ingrediente não pode ser alterado casualmente.');
            }
        });

        static::deleting(function (self $concept): void {
            if ($concept->is_protected) {
                throw new DomainException('Um conceito protegido de ingrediente não pode ser excluído.');
            }
        });
    }

    public function businessTerms(): HasMany
    {
        return $this->hasMany(IngredientBusinessTerm::class);
    }

    public function bindings(): HasMany
    {
        return $this->hasMany(IngredientConceptBinding::class);
    }
}
