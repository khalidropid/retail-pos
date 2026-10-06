<x-filament-widgets::widget>
    <x-filament::section heading="الأكثر مبيعًا هذا الشهر">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-gray-500"><th class="p-1 text-start">المنتج</th><th class="p-1 text-end">الكمية</th><th class="p-1 text-end">الإيراد</th></tr>
            </thead>
            <tbody>
                @forelse ($this->getProducts() as $p)
                    <tr class="border-t border-gray-100 dark:border-gray-800">
                        <td class="p-1">{{ $p['name'] }}</td>
                        <td class="p-1 text-end">{{ $p['qty'] }}</td>
                        <td class="p-1 text-end">{{ money($p['revenue']) }}</td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-4 text-center text-gray-500">لا توجد مبيعات هذا الشهر</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-filament::section>
</x-filament-widgets::widget>
