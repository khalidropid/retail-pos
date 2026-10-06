<?php

namespace Tests\Feature\Performance;

use App\Filament\Pages\Pos;
use App\Models\Unit;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\BuildsDomainData;
use Tests\TestCase;

class PerformanceTest extends TestCase
{
    use BuildsDomainData;

    public function test_pos_search_and_barcode_lookup_use_constant_queries(): void
    {
        $cashier = $this->userWithRole('cashier');
        for ($i = 0; $i < 30; $i++) {
            $this->stocked(5, ['name' => "منتج تجريبي {$i}"]);
        }
        $pos = Livewire::actingAs($cashier)->test(Pos::class)->instance();

        DB::flushQueryLog();
        DB::enableQueryLog();
        $results = $pos->searchProducts('منتج');
        $searchQueries = count(DB::getQueryLog());

        DB::flushQueryLog();
        $pos->findByCode('0000000000000');
        $lookupQueries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertCount(24, $results);
        $this->assertLessThanOrEqual(3, $searchQueries, 'product search must not be N+1');
        $this->assertLessThanOrEqual(2, $lookupQueries);
    }

    public function test_search_uses_trigram_index_on_large_catalog(): void
    {
        Unit::factory()->create();
        DB::statement("INSERT INTO products (name, name_normalized, sku, unit_id, cost_price, sale_price, tax_rate, stock_quantity, created_at, updated_at)
            SELECT 'صنف ' || g, 'صنف ' || g, 'BULK-' || g, (SELECT id FROM units LIMIT 1), 1, 2, 0, 0, now(), now()
            FROM generate_series(1, 20000) g", []);
        DB::statement('ANALYZE products');

        // On a small table the planner may rightly prefer a sequential scan;
        // disabling it proves the trigram index can serve Arabic LIKE searches.
        DB::statement('SET LOCAL enable_seqscan = off');
        $plan = collect(DB::select("EXPLAIN SELECT id FROM products WHERE name_normalized LIKE '%صنف 1999%'"))->pluck('QUERY PLAN')->implode("\n");

        $this->assertStringContainsString('products_name_trgm', $plan);
    }
}
