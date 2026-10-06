<?php

namespace App\Filament\Pages;

use App\Services\ReceivableService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Url;
use UnitEnum;

class Receivables extends Page
{
    protected string $view = 'filament.pages.receivables';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedScale;

    protected static string|UnitEnum|null $navigationGroup = 'العملاء والذمم';

    protected static ?string $title = 'الذمم المدينة وأعمار الديون';

    protected static ?string $navigationLabel = 'الذمم المدينة';

    protected static ?string $slug = 'receivables';

    protected static ?int $navigationSort = 4;

    #[Url]
    public ?string $asOf = null;

    public static function canAccess(): bool
    {
        return auth()->user()?->can('customers.view_debts') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(4)->components([
            DatePicker::make('asOf')->label('حتى تاريخ')->live()->maxDate(today()),
        ]);
    }

    /** @return array<string, mixed> */
    public function getData(): array
    {
        $service = app(ReceivableService::class);
        $asOf = $this->asOf ? Carbon::parse($this->asOf) : today();

        return [
            'overview' => $service->overview(),
            'aging' => $service->aging($asOf),
            'buckets' => ReceivableService::AGING_BUCKETS,
            'asOf' => $asOf,
        ];
    }
}
