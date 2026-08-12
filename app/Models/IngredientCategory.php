<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IngredientCategory extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'notes', 'active'];

    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }

    public function ingredients(): HasMany
    {
        return $this->hasMany(Ingredient::class);
    }
}
