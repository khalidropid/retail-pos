<!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: Tahoma, 'Segoe UI', Arial, sans-serif; margin: 0; color: #000; background: #f3f4f6; }
        .sheet { background: #fff; margin: 12px auto; padding: 12px; }
        .receipt { width: {{ (int) settings('receipt.paper_width', 80) }}mm; font-size: 12px; }
        .a4 { width: 210mm; min-height: 297mm; font-size: 13px; padding: 16mm; }
        .center { text-align: center; }
        .bold { font-weight: bold; }
        .muted { color: #444; }
        .logo { max-height: 60px; max-width: 70%; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        table { width: 100%; border-collapse: collapse; }
        th, td { padding: 3px 2px; text-align: right; vertical-align: top; }
        .a4 th, .a4 td { border: 1px solid #ccc; padding: 6px; }
        .a4 th { background: #f3f4f6; }
        .num { text-align: left; white-space: nowrap; }
        .ltr { direction: ltr; unicode-bidi: embed; }
        .totals td { padding: 2px; }
        .big { font-size: 15px; font-weight: bold; }
        .toolbar { text-align: center; margin: 10px; }
        .toolbar button { padding: 8px 18px; font-size: 14px; cursor: pointer; }
        @media print {
            body { background: #fff; }
            .sheet { margin: 0; padding: 0 2mm; box-shadow: none; }
            .a4 { padding: 10mm; }
            .toolbar { display: none; }
            @page { margin: 0; }
        }
    </style>
</head>
<body>
    <div class="toolbar">
        <button onclick="window.print()">طباعة</button>
        <button onclick="window.close()">إغلاق</button>
    </div>
    @yield('content')
    @if (request()->boolean('autoprint', true))
        <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
    @endif
</body>
</html>
