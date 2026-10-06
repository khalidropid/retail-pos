<x-filament-panels::page>
    {{ $this->form }}

    @php($r = $this->getReport())
    @php($cols = $r['columns'])

    <x-filament::section :heading="$r['title']">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-gray-200 text-gray-600 dark:border-gray-700 dark:text-gray-300">
                        @foreach ($cols as $key => $label)
                            <th @class(['p-2', 'text-end' => in_array($key, $r['money']), 'text-start' => ! in_array($key, $r['money'])])>{{ $label }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @forelse ($r['rows'] as $row)
                        <tr class="border-b border-gray-100 dark:border-gray-800">
                            @foreach ($cols as $key => $label)
                                <td @class(['p-2', 'text-end' => in_array($key, $r['money'])])>{{ \App\Filament\Pages\Reports\ReportPage::formatCell($key, $row[$key] ?? '', $r['money']) }}</td>
                            @endforeach
                        </tr>
                    @empty
                        <tr><td colspan="{{ count($cols) }}" class="p-6 text-center text-gray-500">لا توجد بيانات للفترة المحددة</td></tr>
                    @endforelse
                </tbody>
                @if ($r['totals'] !== [] && $r['rows']->isNotEmpty())
                    <tfoot>
                        <tr class="font-bold">
                            @foreach ($cols as $key => $label)
                                <td @class(['p-2', 'text-end' => in_array($key, $r['money'])])>
                                    @if ($loop->first)
                                        الإجمالي
                                    @elseif (array_key_exists($key, $r['totals']))
                                        {{ \App\Filament\Pages\Reports\ReportPage::formatCell($key, $r['totals'][$key], $r['money']) }}
                                    @endif
                                </td>
                            @endforeach
                        </tr>
                    </tfoot>
                @endif
            </table>
        </div>
        <div class="mt-2 text-xs text-gray-500">المبالغ بـ {{ settings('currency.symbol') }} — عدد الصفوف: {{ $r['rows']->count() }}</div>
    </x-filament::section>
</x-filament-panels::page>
