<x-filament-panels::page>
    {{ $this->form }}

    @php($data = $this->getStatement())

    @if ($data)
        @php($s = $data['statement'])
        @php($sum = $data['summary'])

        <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
            @foreach ([
                ['إجمالي المبيعات', $sum['total_sales'], null],
                ['إجمالي المدفوعات', $sum['total_payments'], 'success'],
                ['إجمالي المرتجعات', $sum['total_returns'], null],
                ['الدين الحالي', $sum['total_debt'], 'danger'],
                ['المتأخر', $sum['overdue'], 'danger'],
                ['الحد الائتماني', $sum['credit_limit'], null],
                ['الرصيد المتاح', $sum['available_credit'], 'success'],
            ] as [$label, $value, $color])
                <x-filament::section compact>
                    <div class="text-xs text-gray-500">{{ $label }}</div>
                    <div @class(['text-lg font-bold', 'text-danger-600' => $color === 'danger', 'text-success-600' => $color === 'success'])>{{ money($value) }}</div>
                </x-filament::section>
            @endforeach
        </div>

        <div class="flex gap-2">
            <x-filament::button tag="a" :href="$data['printUrl']" target="_blank" icon="heroicon-o-printer" color="gray">طباعة</x-filament::button>
            <x-filament::button tag="a" :href="$data['pdfUrl']" target="_blank" icon="heroicon-o-document-arrow-down">تصدير PDF</x-filament::button>
        </div>

        <x-filament::section>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300">
                            <th class="p-2 text-start">التاريخ</th>
                            <th class="p-2 text-start">البيان</th>
                            <th class="p-2 text-start">المرجع</th>
                            <th class="p-2 text-end">مدين</th>
                            <th class="p-2 text-end">دائن</th>
                            <th class="p-2 text-end">الرصيد</th>
                            <th class="p-2 text-start">المستخدم</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr class="bg-gray-50 font-semibold dark:bg-gray-800">
                            <td class="p-2">{{ $s['from']?->format('Y-m-d') }}</td>
                            <td class="p-2" colspan="4">الرصيد السابق</td>
                            <td class="p-2 text-end">{{ money($s['opening_balance']) }}</td>
                            <td></td>
                        </tr>
                        @forelse ($s['entries'] as $e)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="p-2 whitespace-nowrap">{{ $e->entry_date->format('Y-m-d H:i') }}</td>
                                <td class="p-2">
                                    <x-filament::badge :color="$e->type->getColor()">{{ $e->type->getLabel() }}</x-filament::badge>
                                    <div class="text-xs text-gray-500">{{ $e->description }}</div>
                                </td>
                                <td class="p-2">
                                    @if ($e->reference instanceof \App\Models\Sale)
                                        <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Sales\SaleResource::getUrl('view', ['record' => $e->reference]) }}">{{ $e->reference->number }}</a>
                                    @elseif ($e->reference instanceof \App\Models\CustomerPayment)
                                        <a class="text-primary-600 underline" href="{{ \App\Filament\Resources\CustomerPayments\CustomerPaymentResource::getUrl('view', ['record' => $e->reference]) }}">{{ $e->reference->number }}</a>
                                    @else
                                        {{ $e->reference?->number ?? '-' }}
                                    @endif
                                </td>
                                <td class="p-2 text-end text-danger-600">{{ $e->debit > 0 ? money($e->debit, false) : '' }}</td>
                                <td class="p-2 text-end text-success-600">{{ $e->credit > 0 ? money($e->credit, false) : '' }}</td>
                                <td class="p-2 text-end font-semibold">{{ money($e->balance_after, false) }}</td>
                                <td class="p-2 text-xs">{{ $e->user?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="7" class="p-4 text-center text-gray-500">لا توجد حركات في هذه الفترة</td></tr>
                        @endforelse
                        <tr class="font-bold">
                            <td class="p-2" colspan="3">المجموع</td>
                            <td class="p-2 text-end">{{ money($s['total_debit'], false) }}</td>
                            <td class="p-2 text-end">{{ money($s['total_credit'], false) }}</td>
                            <td class="p-2 text-end">{{ money($s['closing_balance']) }}</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section>
            <div class="p-6 text-center text-gray-500">اختر العميل لعرض كشف الحساب</div>
        </x-filament::section>
    @endif
</x-filament-panels::page>
