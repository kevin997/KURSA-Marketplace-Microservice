<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = ['seller_id', 'name', 'description', 'price', 'template_id', 'thumbnail_url', 'is_published'];

    public function categories()
    {
        return $this->belongsToMany(Category::class);
    }

    protected $casts = [
        'is_published' => 'boolean',
    ];

    public function seller()
    {
        return $this->belongsTo(Seller::class);
    }
}
