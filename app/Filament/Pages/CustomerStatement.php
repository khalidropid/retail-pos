<?php

namespace App\Filament\Pages;

use App\Models\Customer;
use App\Services\CustomerStatementService;
use BackedEnum;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;
use UnitEnum;

class CustomerStatement extends Page
{
    protected string $view = 'filament.pages.customer-statement';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'العملاء والذمم';

    protected static ?string $title = 'كشف حساب العميل';

    protected static ?string $slug = 'customer-statement';

    protected static ?int $navigationSort = 3;

    #[Url]
    public ?int $customer = null;

    #[Url]
    public ?string $from = null;

    #[Url]
    public ?string $to = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->can('customers.view') && $user->can('customers.view_debts');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Select::make('customer')->label('العميل')->searchable()->live()
                ->getSearchResultsUsing(fn (string $search) => Customer::query()->search($search)->limit(30)->pluck('name', 'id'))
                ->getOptionLabelUsing(fn ($value) => Customer::withTrashed()->find($value)?->name),
            DatePicker::make('from')->label('من تاريخ')->live(),
            DatePicker::make('to')->label('إلى تاريخ')->live()->afterOrEqual('from'),
        ]);
    }

    /** @return array<string, mixed>|null */
    public function getStatement(): ?array
    {
        if (! $this->customer || ! ($customer = Customer::withTrashed()->find($this->customer))) {
            return null;
        }

        $service = app(CustomerStatementService::class);

        return [
            'statement' => $service->statement($customer, $this->from, $this->to),
            'summary' => $service->summary($customer),
            'printUrl' => route('print.customer-statement', ['customer' => $customer, 'from' => $this->from, 'to' => $this->to]),
            'pdfUrl' => route('print.customer-statement', ['customer' => $customer, 'from' => $this->from, 'to' => $this->to, 'format' => 'pdf']),
        ];
    }
}
