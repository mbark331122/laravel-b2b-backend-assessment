<?php

namespace Database\Seeders;

use App\Models\ProductCategory;
use Illuminate\Database\Seeder;

class ProductCategorySeeder extends Seeder
{
    public const SUGAR = 'Sugar';

    public const GRAINS = 'Grains';

    public function run(): void
    {
        foreach ([self::SUGAR, self::GRAINS] as $name) {
            $category = ProductCategory::query()->firstOrNew(['name' => $name]);
            $category->status = ProductCategory::STATUS_ACTIVE;
            $category->save();
        }
    }
}
