<?php

namespace App\Filament\Support;

use App\Models\PaymentAccountType;
use App\Models\PaymentMethod;
use App\Services\Payments\PaymentDetailsResolver;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Payment method / account type / reference fields driven by the
 * configuration stored on the payment method and account type records.
 */
final class PaymentFields
{
    /**
     * @return array<int, Component>
     */
    public static function make(
        string $method = 'payment_method_id',
        string $accountType = 'payment_account_type_id',
        string $reference = 'reference',
        string $methodLabel = 'طريقة الدفع',
        bool $required = true,
        bool $atPos = false,
    ): array {
        $methodModel = fn (Get $get) => ($id = $get($method)) ? PaymentMethod::query()->find($id) : null;
        $typeModel = fn (Get $get) => ($id = $get($accountType)) ? PaymentAccountType::query()->find($id) : null;

        return [
            Select::make($method)->label($methodLabel)->required($required)->live()
                ->options(fn () => PaymentMethod::query()->active()->when($atPos, fn ($q) => $q->where('available_at_pos', true))->pluck('name', 'id'))
                ->default(fn () => PaymentMethod::query()->where('code', 'cash')->value('id'))
                ->afterStateUpdated(fn ($set) => $set($accountType, null)),
            Select::make($accountType)->label('نوع الحساب')->live()
                ->options(fn () => PaymentAccountType::query()->active()->when($atPos, fn ($q) => $q->where('available_at_pos', true))->pluck('name', 'id'))
                ->visible(fn (Get $get) => (bool) $methodModel($get)?->requires_account_type)
                ->required(fn (Get $get) => (bool) $methodModel($get)?->requires_account_type),
            TextInput::make($reference)->label(fn (Get $get) => $methodModel($get)?->requires_reference || $typeModel($get)?->requires_reference
                    ? 'رقم المرجع' : 'المرجع (اختياري)')
                ->maxLength(PaymentDetailsResolver::REFERENCE_MAX)
                ->minLength(PaymentDetailsResolver::REFERENCE_MIN)
                ->required(fn (Get $get) => (bool) ($methodModel($get)?->requires_reference || $typeModel($get)?->requires_reference))
                ->helperText(fn (Get $get) => $methodModel($get)?->requires_verification
                    ? 'رقم المرجع لا يثبت وصول التحويل؛ ستُسجّل الدفعة "بانتظار التأكيد".' : null),
        ];
    }
}
