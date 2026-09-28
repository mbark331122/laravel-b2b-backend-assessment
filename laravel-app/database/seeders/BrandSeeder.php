<?php

namespace Database\Seeders;

use App\Models\Brand;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class BrandSeeder extends Seeder
{
    public const AL_OSRA = 'Al Osra';

    public const GRAINCO = 'GrainCo';

    public function run(): void
    {
        foreach ([self::AL_OSRA, self::GRAINCO] as $name) {
            $brand = Brand::query()->firstOrNew(['name' => $name]);
            $brand->slug = Str::slug($name);
            $brand->status = Brand::STATUS_ACTIVE;
            $brand->save();
        }
    }
}
