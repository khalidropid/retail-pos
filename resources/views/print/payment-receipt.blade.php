@extends('print.layout')
@section('title', 'إيصال سداد '.$payment->number)
@section('content')
<div class="sheet receipt">
    @include('print.partials.store-header')
    <hr>
    <div class="center big">إيصال سداد</div>
    @if ($payment->isVoided())
        <div class="center big">*** دفعة معكوسة ***</div>
    @endif
    <table>
        <tr><td>رقم الإيصال:</td><td class="bold">{{ $payment->number }}</td></tr>
        <tr><td>التاريخ:</td><td><span class="ltr">{{ $payment->payment_date->format('Y-m-d H:i') }}</span></td></tr>
        <tr><td>العميل:</td><td class="bold">{{ $payment->customer?->name ?? 'عميل نقدي' }}</td></tr>
        <tr><td>طريقة الدفع:</td><td>{{ $payment->paymentMethod->name }}</td></tr>
        @if ($payment->reference)<tr><td>المرجع:</td><td>{{ $payment->reference }}</td></tr>@endif
    </table>
    <hr>
    @if ($payment->allocations->isNotEmpty())
        <div class="bold">الفواتير المسددة</div>
        <table>
            <thead><tr><th>الفاتورة</th><th class="num">المبلغ</th></tr></thead>
            <tbody>
            @foreach ($payment->allocations as $allocation)
                <tr><td>{{ $allocation->sale->number }}</td><td class="num">{{ money($allocation->amount) }}</td></tr>
            @endforeach
            </tbody>
        </table>
        <hr>
    @endif
    <table class="totals">
        @if ($payment->balance_before !== null)
            <tr><td>الرصيد قبل الدفع</td><td class="num">{{ money($payment->balance_before) }}</td></tr>
        @endif
        <tr class="big"><td>المبلغ المدفوع</td><td class="num">{{ money($payment->amount) }}</td></tr>
        @if ($payment->balance_after !== null)
            <tr class="bold"><td>الرصيد بعد الدفع</td><td class="num">{{ money($payment->balance_after) }}</td></tr>
        @endif
    </table>
    <hr>
    <table>
        <tr><td>الموظف:</td><td>{{ $payment->user->name }}</td></tr>
    </table>
    <br>
    <div class="center">التوقيع: ....................</div>
    <hr>
    <div class="center bold">{{ settings('receipt.footer') ?: settings('invoice.footer') }}</div>
</div>
@endsection
