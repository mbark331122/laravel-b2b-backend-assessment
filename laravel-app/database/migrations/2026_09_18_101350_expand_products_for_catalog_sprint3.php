<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->foreignId('brand_id')->nullable()->after('product_category_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('maximum_order_quantity')->nullable()->after('minimum_order_quantity');
            $table->unsignedInteger('quantity_increment')->default(1)->after('maximum_order_quantity');
        });

        // Map Sprint 2 active/inactive into lifecycle states for existing rows.
        DB::table('products')->where('status', 'active')->update(['status' => 'published']);
        DB::table('products')->where('status', 'inactive')->update(['status' => 'archived']);
    }

    public function down(): void
    {
        DB::table('products')->where('status', 'published')->update(['status' => 'active']);
        DB::table('products')->where('status', 'archived')->update(['status' => 'inactive']);

        Schema::table('products', function (Blueprint $table) {
            $table->dropConstrainedForeignId('brand_id');
            $table->dropColumn(['maximum_order_quantity', 'quantity_increment']);
        });
    }
};
