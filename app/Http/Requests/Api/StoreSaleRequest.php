<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sales.create');
    }

    public function rules(): array
    {
        return [
            'sale_type' => ['required', Rule::in(['cash', 'credit'])],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id', 'required_if:sale_type,credit'],
            'payment_method_id' => ['nullable', 'integer', 'exists:payment_methods,id'],
            'paid_amount' => ['nullable', 'numeric', 'min:0'],
            'account_type_id' => ['nullable', 'integer'],
            'payment_reference' => ['nullable', 'string', 'max:100'],
            'payments' => ['nullable', 'array', 'max:10'],
            'payments.*.payment_method_id' => ['required', 'integer', 'exists:payment_methods,id'],
            'payments.*.amount' => ['required', 'numeric', 'gt:0'],
            'payments.*.account_type_id' => ['nullable', 'integer'],
            'payments.*.reference' => ['nullable', 'string', 'max:100'],
            'amount_tendered' => ['nullable', 'numeric', 'min:0'],
            'invoice_discount' => ['nullable', 'numeric', 'min:0'],
            'due_date' => ['nullable', 'date', 'after_or_equal:today'],
            'override_credit_limit' => ['boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', 'integer', 'distinct', 'exists:products,id'],
            'items.*.quantity' => ['required', 'numeric', 'gt:0'],
            'items.*.unit_price' => ['nullable', 'numeric', 'min:0'],
            'items.*.discount' => ['nullable', 'numeric', 'min:0'],
        ];
    }

    public function attributes(): array
    {
        return [
            'sale_type' => 'نوع البيع',
            'items.*.product_id' => 'المنتج',
            'items.*.quantity' => 'الكمية',
            'items.*.unit_price' => 'السعر',
            'items.*.discount' => 'الخصم',
            'invoice_discount' => 'خصم الفاتورة',
            'paid_amount' => 'المبلغ المدفوع',
            'amount_tendered' => 'المبلغ المستلم',
        ];
    }
}
