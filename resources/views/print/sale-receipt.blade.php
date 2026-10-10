@extends('print.layout')
@section('title', 'إيصال '.$sale->number)
@section('content')
<div class="sheet receipt">
    @include('print.partials.store-header')
    <hr>
    <table>
        <tr><td>رقم الفاتورة:</td><td class="bold">{{ $sale->number }}</td></tr>
        <tr><td>التاريخ:</td><td><span class="ltr">{{ $sale->sale_date->format('Y-m-d H:i') }}</span></td></tr>
        <tr><td>الكاشير:</td><td>{{ $sale->user->name }}</td></tr>
        <tr><td>العميل:</td><td>{{ $sale->customer?->name ?? 'عميل نقدي' }}</td></tr>
        <tr><td>نوع الدفع:</td><td>@if ($sale->sale_type === \App\Enums\SaleType::Credit)آجل@elseif ($sale->allocations->count() > 1)دفع متعدد@else{{ $sale->allocations->first()?->payment->methodLabel() ?? '-' }}@endif</td></tr>
        @if ($sale->isCancelled())
            <tr><td colspan="2" class="center big">*** فاتورة ملغاة ***</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <thead><tr><th>الصنف</th><th class="num">الكمية</th><th class="num">السعر</th><th class="num">المجموع</th></tr></thead>
        <tbody>
        @foreach ($sale->items as $item)
            <tr>
                <td>{{ $item->product_name }}</td>
                <td class="num">{{ \App\Support\Money::formatQty($item->quantity) }}</td>
                <td class="num">{{ money($item->unit_price, false) }}</td>
                <td class="num">{{ money(\App\Support\Money::mul($item->quantity, $item->unit_price), false) }}</td>
            </tr>
            @if ($item->discount_amount > 0)
                <tr><td colspan="3" class="muted">خصم</td><td class="num">-{{ money($item->discount_amount, false) }}</td></tr>
            @endif
        @endforeach
        </tbody>
    </table>
    <hr>
    <table class="totals">
        <tr><td>المجموع</td><td class="num">{{ money($sale->subtotal) }}</td></tr>
        @if ($sale->discount_amount > 0)<tr><td>الخصم</td><td class="num">-{{ money($sale->discount_amount) }}</td></tr>@endif
        @if ($sale->tax_amount > 0)<tr><td>{{ settings('tax.label', 'الضريبة') }}{{ settings('tax.prices_include_tax') ? ' (ضمن السعر)' : '' }}</td><td class="num">{{ money($sale->tax_amount) }}</td></tr>@endif
        <tr class="big"><td>الإجمالي</td><td class="num">{{ money($sale->total) }}</td></tr>
        <tr><td>المدفوع</td><td class="num">{{ money($sale->paid_amount) }}</td></tr>
        @foreach ($sale->allocations as $allocation)
            @if ($allocation->payment->status->value === 'active')
                <tr class="muted"><td>&nbsp;&nbsp;{{ $allocation->payment->methodLabel() }}@if ($allocation->payment->reference) <br>&nbsp;&nbsp;مرجع: <span class="ltr">{{ $allocation->payment->reference }}</span>@endif</td>
                    <td class="num">{{ money($allocation->amount) }}</td></tr>
            @endif
        @endforeach
        @if ($sale->amount_tendered)
            <tr><td>المبلغ المستلم</td><td class="num">{{ money($sale->amount_tendered) }}</td></tr>
            <tr><td>الباقي</td><td class="num">{{ money($sale->change_given) }}</td></tr>
        @endif
        @if ($sale->returned_amount > 0)<tr><td>المرتجع</td><td class="num">-{{ money($sale->returned_amount) }}</td></tr>@endif
        <tr class="bold"><td>المتبقي</td><td class="num">{{ money($sale->balance_due) }}</td></tr>
        @if ($sale->due_date)
            <tr class="bold"><td>تاريخ الاستحقاق</td><td class="num"><span class="ltr">{{ $sale->due_date->format('Y-m-d') }}</span></td></tr>
        @endif
    </table>
    <hr>
    @if (settings('invoice.terms'))<div class="center muted">{{ settings('invoice.terms') }}</div>@endif
    <div class="center bold">{{ settings('receipt.footer') ?: settings('invoice.footer') }}</div>
</div>
@endsection
