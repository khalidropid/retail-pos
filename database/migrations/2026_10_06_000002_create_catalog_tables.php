<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Trigram index support for fast fuzzy Arabic/English search.
        DB::statement('CREATE EXTENSION IF NOT EXISTS pg_trgm');

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('units', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50);
            $table->string('short_name', 20);
            $table->boolean('allow_decimal')->default(false);
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('name_normalized', 255);
            $table->string('sku', 64);
            $table->string('barcode', 64)->nullable();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('brand_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();
            $table->decimal('cost_price', 18, 4)->default(0);
            $table->decimal('sale_price', 18, 2)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            // Cached on-hand quantity. Only InventoryService writes it, always
            // together with a stock_movements row inside the same transaction.
            $table->decimal('stock_quantity', 15, 3)->default(0);
            $table->decimal('reorder_level', 15, 3)->default(0);
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->boolean('track_stock')->default(true);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'category_id']);
        });

        DB::statement('CREATE UNIQUE INDEX products_sku_unique ON products (lower(sku)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX products_barcode_unique ON products (barcode) WHERE barcode IS NOT NULL AND deleted_at IS NULL');
        DB::statement('CREATE INDEX products_name_trgm ON products USING gin (name_normalized gin_trgm_ops)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_prices_non_negative CHECK (cost_price >= 0 AND sale_price >= 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_tax_rate_range CHECK (tax_rate >= 0 AND tax_rate <= 100)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT products_levels_non_negative CHECK (reorder_level >= 0 AND min_stock >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
        Schema::dropIfExists('units');
        Schema::dropIfExists('brands');
        Schema::dropIfExists('categories');
    }
};
