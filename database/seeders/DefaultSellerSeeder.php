<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DefaultSellerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // KURSA is the default seller. Assuming User ID 1 is the super admin/Kursa account.
        $seller = \App\Models\Seller::firstOrCreate(
            ['user_id' => 1],
            [
                'company_name' => 'KURSA',
                'is_verified' => true,
                'verified_at' => now(),
            ]
        );

        // Seed some products
        if ($seller->products()->count() === 0) {
            // Create Categories
        $businessCat = \App\Models\Category::create(['name' => 'Business', 'slug' => 'business']);
        $marketingCat = \App\Models\Category::create(['name' => 'Marketing', 'slug' => 'marketing']);
        $devCat = \App\Models\Category::create(['name' => 'Development', 'slug' => 'development']);

        $products = [
            
                [
                    'name' => 'Leadership Mastery',
                    'description' => 'A comprehensive guide to becoming a great leader.',
                    'price' => 49.99,
                    'template_id' => 101,
                    'is_published' => true,
                    'categories' => [$businessCat->id],
                ],
                [
                    'name' => 'Digital Marketing 101',
                    'description' => 'Learn the basics of digital marketing.',
                    'price' => 29.99,
                    'template_id' => 102,
                    'is_published' => true,
                    'categories' => [$marketingCat->id],
                ],
                [
                    'name' => 'Advanced Python',
                    'description' => 'Deep dive into Python programming.',
                    'price' => 99.00,
                    'template_id' => 103,
                    'is_published' => true,
                    'categories' => [$devCat->id],
                ],
            
        ];

        foreach ($products as $productData) {
            $categories = $productData['categories'];
            unset($productData['categories']);
            
            $product = $seller->products()->create($productData);
            $product->categories()->attach($categories);
        }}
    }
}
