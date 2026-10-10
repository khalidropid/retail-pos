<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <title>ملصقات الباركود</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body { font-family: Tahoma, Arial, sans-serif; color: #000; background: #e5e7eb; }
        .toolbar { text-align: center; padding: 10px; }
        .toolbar button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
        .warn { text-align: center; color: #b91c1c; padding: 6px; font-size: 13px; }
        .label {
            width: {{ $size['width'] }}mm; height: {{ $size['height'] }}mm; background: #fff;
            padding: 1mm 1.5mm; overflow: hidden; display: flex; flex-direction: column;
            align-items: center; justify-content: center; text-align: center;
        }
        .name { font-size: {{ $size['height'] < 28 ? 7 : 8 }}pt; font-weight: bold; line-height: 1.1; max-height: 2.3em; overflow: hidden; }
        .store { font-size: 6pt; }
        .bars { width: 100%; display: flex; justify-content: center; margin: .5mm 0; }
        /* Keep quiet zones: the bars stay centered with white margins; never stretch them. */
        .bars svg { max-width: 100%; height: {{ round($size['height'] * 0.42, 1) }}mm; }
        .code { font-family: 'Courier New', monospace; font-size: 8pt; letter-spacing: .5px; direction: ltr; }
        .meta { font-size: 7pt; display: flex; gap: 2mm; justify-content: center; direction: rtl; }
        .price { font-weight: bold; font-size: 8pt; }
        @if ($size['type'] === 'roll')
            .roll .label { margin: 4mm auto; box-shadow: 0 0 2px #999; }
            @page { size: {{ $size['width'] }}mm {{ $size['height'] }}mm; margin: 0; }
            @media print { .roll .label { margin: 0; box-shadow: none; page-break-after: always; } }
        @else
            .sheet { width: 210mm; margin: 0 auto; background: #fff; display: grid;
                     grid-template-columns: repeat({{ $size['columns'] }}, {{ $size['width'] }}mm);
                     grid-auto-rows: {{ $size['height'] }}mm; justify-content: center; align-content: start; }
            .sheet .label { outline: 1px dashed #ddd; }
            @page { size: A4; margin: 0; }
            @media print { .sheet .label { outline: none; } }
        @endif
        @media print { body { background: #fff; } .toolbar, .warn { display: none; } }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">طباعة ({{ count($labels) }} ملصق)</button>
        <button onclick="window.close()">إغلاق</button>
    </div>
    @if ($missing->isNotEmpty())
        <div class="warn">منتجات بدون باركود لم تُطبع: {{ $missing->implode('، ') }}</div>
    @endif

    <div class="{{ $size['type'] === 'roll' ? 'roll' : 'sheet' }}">
        @foreach ($labels as $label)
            @php($p = $label['product'])
            <div class="label">
                @if (settings('barcode.show_store_name'))<div class="store">{{ settings('store.name') }}</div>@endif
                <div class="name">{{ $p->name }}</div>
                <div class="bars">{!! $label['svg'] !!}</div>
                <div class="code">{{ $p->barcode }}</div>
                <div class="meta">
                    @if (settings('barcode.show_sku', true) && $p->sku)<span dir="ltr">{{ $p->sku }}</span>@endif
                    @if ($showPrice)<span class="price">{{ money($p->sale_price) }}</span>@endif
                </div>
            </div>
        @endforeach
    </div>
</body>
</html>
