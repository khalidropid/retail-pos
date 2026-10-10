<?php

namespace App\Filament\Resources\CustomerPayments\Pages;

use App\Filament\Resources\CustomerPayments\CustomerPaymentResource;
use App\Filament\Support\Domain;
use App\Models\Customer;
use App\Services\CustomerPaymentService;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateCustomerPayment extends CreateRecord
{
    protected static string $resource = CustomerPaymentResource::class;

    protected static ?string $title = 'تسجيل دفعة من عميل (سند قبض)';

    public function mount(): void
    {
        parent::mount();

        if ($customerId = request()->integer('customer')) {
            $this->form->fill([
                'customer_id' => $customerId,
                'allocations' => CustomerPaymentResource::openInvoiceRows($customerId),
                'auto_allocate' => true,
            ]);
        }
    }

    protected function handleRecordCreation(array $data): Model
    {
        $allocations = null;
        if (! ($data['auto_allocate'] ?? true)) {
            $allocations = collect($data['allocations'] ?? [])
                ->mapWithKeys(fn ($row) => [(int) $row['sale_id'] => (string) ($row['amount'] ?? 0)])
                ->all();
        }

        return Domain::run(fn () => app(CustomerPaymentService::class)->recordCustomerPayment(
            Customer::query()->findOrFail($data['customer_id']),
            (string) $data['amount'],
            (int) $data['payment_method_id'],
            auth()->user(),
            $allocations,
            $data['reference'] ?? null,
            $data['notes'] ?? null,
            $data['payment_account_type_id'] ?? null,
        ));
    }

    protected function getRedirectUrl(): string
    {
        return $this->getResource()::getUrl('view', ['record' => $this->record]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'تم تسجيل الدفعة بنجاح';
    }
}
