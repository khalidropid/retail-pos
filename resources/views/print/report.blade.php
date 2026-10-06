@extends('print.layout')
@section('title', $r['title'])
@section('content')
<div class="sheet a4">
    <div class="center big">{{ settings('store.name') }}</div>
    <div class="center big">{{ $r['title'] }}</div>
    @if ($r['dated'])
        <div class="center muted">الفترة: <span class="ltr">{{ $from }}</span> — <span class="ltr">{{ $to }}</span></div>
    @endif
    <div class="center muted">تاريخ الطباعة: <span class="ltr">{{ now()->format('Y-m-d H:i') }}</span></div>
    <br>
    <table>
        <thead>
            <tr>@foreach ($r['columns'] as $label)<th>{{ $label }}</th>@endforeach</tr>
        </thead>
        <tbody>
            @forelse ($r['rows'] as $row)
                <tr>
                    @foreach ($r['columns'] as $key => $label)
                        <td @class(['num' => in_array($key, $r['money'])])>{{ \App\Filament\Pages\Reports\ReportPage::formatCell($key, $row[$key] ?? '', $r['money']) }}</td>
                    @endforeach
                </tr>
            @empty
                <tr><td colspan="{{ count($r['columns']) }}" class="center">لا توجد بيانات</td></tr>
            @endforelse
        </tbody>
        @if ($r['totals'] !== [] && $r['rows']->isNotEmpty())
            <tfoot>
                <tr class="bold">
                    @foreach ($r['columns'] as $key => $label)
                        <td @class(['num' => in_array($key, $r['money'])])>
                            @if ($loop->first) الإجمالي @elseif (array_key_exists($key, $r['totals'])) {{ \App\Filament\Pages\Reports\ReportPage::formatCell($key, $r['totals'][$key], $r['money']) }} @endif
                        </td>
                    @endforeach
                </tr>
            </tfoot>
        @endif
    </table>
    <div class="muted">المبالغ بـ {{ settings('currency.symbol') }}</div>
</div>
@endsection
