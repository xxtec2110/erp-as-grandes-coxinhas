<?php

namespace App\Models;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientBusinessTerm extends Model
{
    protected $guarded = ['id', 'ingredient_concept_id', 'term', 'normalized_term', 'active', 'is_protected'];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'is_protected' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updating(function (self $term): void {
            if ($term->getOriginal('is_protected') && $term->isDirty(['ingredient_concept_id', 'term', 'normalized_term', 'active', 'is_protected'])) {
                throw new DomainException('Um termo operacional protegido não pode ser alterado casualmente.');
            }
        });

        static::deleting(function (self $term): void {
            if ($term->is_protected) {
                throw new DomainException('Um termo operacional protegido não pode ser excluído.');
            }
        });
    }

    public function concept(): BelongsTo
    {
        return $this->belongsTo(IngredientConcept::class, 'ingredient_concept_id');
    }
}
