<?php

namespace App\Filament\Pages\Reports;

use App\Services\ReportService;
use App\Support\ExcelExporter;
use App\Support\Money;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use UnitEnum;

/**
 * Shared shell for the report pages: a report picker, an optional date
 * range, a table, print and CSV export.
 */
abstract class ReportPage extends Page
{
    protected string $view = 'filament.pages.report';

    protected static string|UnitEnum|null $navigationGroup = 'التقارير';

    #[Url]
    public ?string $report = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    /**
     * @return array<string, array{label:string, dated:bool}>
     */
    abstract public static function definitions(): array;

    /** Run report $key; returns the ReportService result shape. */
    abstract protected static function run(string $key, ReportService $reports, ?string $from, ?string $to): array;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('reports.view') ?? false;
    }

    public static function canViewProfit(): bool
    {
        return auth()->user()?->can('reports.profit') ?? false;
    }

    public function mount(): void
    {
        $this->report ??= array_key_first(static::definitions());
        $this->from ??= today()->startOfMonth()->toDateString();
        $this->to ??= today()->toDateString();
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            Select::make('report')->label('التقرير')->options(collect(static::definitions())->map(fn ($d) => $d['label']))
                ->required()->live()->selectablePlaceholder(false)->columnSpan(2),
            DatePicker::make('from')->label('من تاريخ')->live()->visible(fn () => $this->isDated()),
            DatePicker::make('to')->label('إلى تاريخ')->live()->afterOrEqual('from')->visible(fn () => $this->isDated()),
        ]);
    }

    public function isDated(): bool
    {
        return static::definitions()[$this->report]['dated'] ?? false;
    }

    /** @return array<string, mixed> */
    public function getReport(): array
    {
        return static::buildReport((string) $this->report, $this->from, $this->to);
    }

    /** @return array<string, mixed> */
    public static function buildReport(string $key, ?string $from, ?string $to): array
    {
        abort_unless(array_key_exists($key, static::definitions()), 404);

        return static::run($key, app(ReportService::class), $from, $to)
            + ['title' => static::definitions()[$key]['label'], 'dated' => static::definitions()[$key]['dated']];
    }

    public static function formatCell(string $key, mixed $value, array $money): string
    {
        return in_array($key, $money, true) ? Money::format($value, false) : (string) $value;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('print')->label('طباعة')->icon('heroicon-o-printer')->color('gray')
                ->url(fn () => route('print.report', [
                    'page' => static::getSlug(), 'report' => $this->report, 'from' => $this->from, 'to' => $this->to,
                ]))->openUrlInNewTab(),
            Action::make('excel')->label('تصدير Excel')->icon('heroicon-o-arrow-down-tray')->color('success')
                ->action(fn () => $this->exportExcel()),
        ];
    }

    public function exportExcel(): BinaryFileResponse
    {
        $report = $this->getReport();

        return ExcelExporter::download($report, $report['title'], 'report-'.$this->report.'-'.now()->format('Ymd-His').'.xlsx');
    }
}
