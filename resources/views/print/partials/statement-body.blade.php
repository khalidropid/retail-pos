@php($c = $s['customer'])
<div class="sheet a4">
    <table class="noborder" style="border:0">
        <tr>
            <td style="border:0; width:60%">
                <div class="big">{{ settings('store.name') }}</div>
                @if (settings('store.address'))<div class="muted">{{ settings('store.address') }}</div>@endif
                @if (settings('store.phone'))<div class="muted">هاتف: {{ settings('store.phone') }}</div>@endif
            </td>
            <td style="border:0" class="num">
                <div class="big">كشف حساب عميل</div>
                <div>تاريخ الطباعة: {{ now()->format('Y-m-d H:i') }}</div>
            </td>
        </tr>
    </table>
    <br>
    <table>
        <tr>
            <th>العميل</th><td class="bold">{{ $c->name }}</td>
            <th>الهاتف</th><td>{{ $c->phone ?? '-' }}</td>
        </tr>
        <tr>
            <th>الفترة</th>
            <td>{{ $s['from']?->format('Y-m-d') ?? 'من البداية' }} — {{ $s['to']?->format('Y-m-d') ?? 'حتى الآن' }}</td>
            <th>الحد الائتماني</th><td class="num">{{ money($summary['credit_limit']) }}</td>
        </tr>
    </table>
    <br>
    <table>
        <thead>
            <tr><th>التاريخ</th><th>البيان</th><th>المرجع</th><th class="num">مدين</th><th class="num">دائن</th><th class="num">الرصيد</th></tr>
        </thead>
        <tbody>
            <tr class="bold">
                <td>{{ $s['from']?->format('Y-m-d') ?? '' }}</td><td colspan="4">الرصيد السابق</td>
                <td class="num">{{ money($s['opening_balance']) }}</td>
            </tr>
            @forelse ($s['entries'] as $e)
                <tr>
                    <td>{{ $e->entry_date->format('Y-m-d') }}</td>
                    <td>{{ $e->type->getLabel() }}<br><span class="muted">{{ $e->description }}</span></td>
                    <td>{{ $e->reference?->number ?? '-' }}</td>
                    <td class="num">{{ $e->debit > 0 ? money($e->debit, false) : '' }}</td>
                    <td class="num">{{ $e->credit > 0 ? money($e->credit, false) : '' }}</td>
                    <td class="num">{{ money($e->balance_after, false) }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="center">لا توجد حركات في هذه الفترة</td></tr>
            @endforelse
            <tr class="bold">
                <td colspan="3">المجموع</td>
                <td class="num">{{ money($s['total_debit'], false) }}</td>
                <td class="num">{{ money($s['total_credit'], false) }}</td>
                <td class="num">{{ money($s['closing_balance']) }}</td>
            </tr>
        </tbody>
    </table>
    <br>
    <table>
        <tr>
            <th>المبيعات</th><td class="num">{{ money($s['totals_by_type']['sale']) }}</td>
            <th>المرتجعات</th><td class="num">{{ money($s['totals_by_type']['sale_return']) }}</td>
            <th>الدفعات</th><td class="num">{{ money($s['totals_by_type']['payment']) }}</td>
        </tr>
        <tr>
            <th>الرصيد الحالي</th><td class="num bold">{{ money($summary['total_debt']) }}</td>
            <th>المتأخر</th><td class="num">{{ money($summary['overdue']) }}</td>
            <th>الرصيد المتاح</th><td class="num">{{ money($summary['available_credit']) }}</td>
        </tr>
    </table>
</div>
