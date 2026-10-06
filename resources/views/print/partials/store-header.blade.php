<div class="center">
    @if (settings('receipt.show_logo', true) && settings('store.logo'))
        <img class="logo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url(settings('store.logo')) }}" alt=""><br>
    @endif
    <div class="big">{{ settings('store.name') }}</div>
    @if (settings('store.address'))<div class="muted">{{ settings('store.address') }}</div>@endif
    @if (settings('store.phone'))<div class="muted">هاتف: <span dir="ltr">{{ settings('store.phone') }}</span></div>@endif
    @if (settings('invoice.show_tax_number', true) && settings('store.tax_number'))<div class="muted">الرقم الضريبي: {{ settings('store.tax_number') }}</div>@endif
    @if (settings('receipt.header'))<div>{{ settings('receipt.header') }}</div>@endif
</div>
