<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Category extends Model
{
    protected $fillable = ['chain_id', 'parent_id', 'external_id', 'store_type', 'name', 'slug', 'level'];

    public function chain(): BelongsTo
    {
        return $this->belongsTo(Chain::class);
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }
}
