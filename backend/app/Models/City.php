<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class City extends Model
{
    protected $fillable = ['name', 'slug', 'latitude', 'longitude'];

    public function stores(): HasMany
    {
        return $this->hasMany(Store::class);
    }
}
