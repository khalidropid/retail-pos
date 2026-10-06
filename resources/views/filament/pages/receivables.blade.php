<x-filament-panels::page>
    @php($d = $this->getData())
    @php($o = $d['overview'])

    <div class="grid grid-cols-2 gap-3 md:grid-cols-5">
        <x-filament::section compact><div class="text-xs text-gray-500">إجمالي ديون العملاء</div><div class="text-xl font-bold">{{ money($o['total']) }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs text-gray-500">الديون الحالية (غير متأخرة)</div><div class="text-xl font-bold text-success-600">{{ money($o['current']) }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs text-gray-500">الديون المتأخرة</div><div class="text-xl font-bold text-danger-600">{{ money($o['overdue']) }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs text-gray-500">عملاء مدينون</div><div class="text-xl font-bold">{{ $o['customers'] }}</div></x-filament::section>
        <x-filament::section compact><div class="text-xs text-gray-500">عملاء متأخرون</div><div class="text-xl font-bold text-danger-600">{{ $o['overdue_customers'] }}</div></x-filament::section>
    </div>

    <div class="flex flex-wrap gap-2">
        <x-filament::button tag="a" color="danger" icon="heroicon-o-exclamation-triangle"
            :href="\App\Filament\Resources\Sales\SaleResource::getUrl('index', ['filters' => ['payment_status' => ['value' => 'overdue']]])">الفواتير المتأخرة</x-filament::button>
        <x-filament::button tag="a" color="warning"
            :href="\App\Filament\Resources\Sales\SaleResource::getUrl('index', ['filters' => ['payment_status' => ['value' => 'unpaid']]])">الفواتير غير المدفوعة</x-filament::button>
        <x-filament::button tag="a" color="info"
            :href="\App\Filament\Resources\Sales\SaleResource::getUrl('index', ['filters' => ['payment_status' => ['value' => 'partially_paid']]])">الفواتير المدفوعة جزئيًا</x-filament::button>
    </div>

    {{ $this->form }}

    <x-filament::section :heading="'أعمار الديون حتى '.$d['asOf']->format('Y-m-d').' (العملاء مرتبون من الأكثر مديونية)'">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        <th class="p-2 text-start">العميل</th>
                        <th class="p-2 text-start">الهاتف</th>
                        <th class="p-2 text-end">الفواتير</th>
                        @foreach ($d['buckets'] as $label)
                            <th class="p-2 text-end">{{ $label }}</th>
                        @endforeach
                        <th class="p-2 text-end">المتأخر</th>
                        <th class="p-2 text-end">الإجمالي</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($d['aging']['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            <td class="p-2">
                                <a class="font-semibold text-primary-600 underline"
                                   href="{{ \App\Filament\Pages\CustomerStatement::getUrl(['customer' => $row['id']]) }}">{{ $row['name'] }}</a>
                            </td>
                            <td class="p-2" dir="ltr">{{ $row['phone'] }}</td>
                            <td class="p-2 text-end">{{ $row['invoices'] }}</td>
                            @foreach (array_keys($d['buckets']) as $key)
                                <td @class(['p-2 text-end', 'text-danger-600' => $key !== 'current' && $row[$key] > 0])>{{ $row[$key] > 0 ? money($row[$key], false) : '-' }}</td>
                            @endforeach
                            <td class="p-2 text-end font-semibold text-danger-600">{{ $row['overdue'] > 0 ? money($row['overdue'], false) : '-' }}</td>
                            <td class="p-2 text-end font-bold">{{ money($row['total']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="p-4 text-center text-gray-500">لا توجد ديون مستحقة</td></tr>
                    @endforelse
                </tbody>
                @if ($d['aging']['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="font-bold">
                            <td class="p-2" colspan="3">الإجمالي</td>
                            @foreach (array_keys($d['buckets']) as $key)
                                <td class="p-2 text-end">{{ money($d['aging']['totals'][$key], false) }}</td>
                            @endforeach
                            <td class="p-2 text-end text-danger-600">{{ money($d['aging']['totals']['overdue'], false) }}</td>
                            <td class="p-2 text-end">{{ money($d['aging']['totals']['total']) }}</td>
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
