<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class InventoryReports extends ReportPage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $title = 'تقارير المخزون';

    protected static ?string $slug = 'reports/inventory';

    protected static ?int $navigationSort = 2;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->can('reports.view') || $user->can('inventory.view'));
    }

    public static function definitions(): array
    {
        $defs = [
            'current' => ['label' => 'المخزون الحالي', 'dated' => false],
            'low' => ['label' => 'المنتجات منخفضة المخزون', 'dated' => false],
            'movements' => ['label' => 'ملخص حركات المخزون', 'dated' => true],
        ];

        if (auth()->user()?->can('products.view_cost')) {
            $defs['valuation'] = ['label' => 'تقييم المخزون', 'dated' => false];
        }

        return $defs;
    }

    protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array
    {
        $cost = auth()->user()?->can('products.view_cost') ?? false;

        return match ($key) {
            'current' => $reports->currentStock($cost),
            'low' => $reports->currentStock($cost, true),
            'movements' => $reports->stockMovementsSummary($from, $to),
            'valuation' => $reports->stockValuation(),
        };
    }
}
