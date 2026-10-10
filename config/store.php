<?php

/*
| Default values for store settings. These are only used to seed the
| `settings` table and as fallbacks; the live values are edited from the
| Settings page in the admin panel and stored in the database.
*/

return [
    'defaults' => [
        'general' => [
            'store.name' => 'متجري',
            'store.logo' => null,
            'store.phone' => null,
            'store.address' => null,
            'store.email' => null,
            'store.tax_number' => null,
            'app.timezone' => 'Asia/Aden',
        ],
        'currency' => [
            'currency.code' => 'YER',
            'currency.symbol' => 'ر.ي',
            'currency.decimals' => 0,
        ],
        'tax' => [
            'tax.enabled' => true,
            'tax.prices_include_tax' => false,
            'tax.default_rate' => '0',
            'tax.label' => 'الضريبة',
        ],
        'invoice' => [
            'invoice.footer' => 'شكرًا لتسوقكم معنا',
            'invoice.terms' => null,
            'invoice.show_tax_number' => true,
        ],
        'receipt' => [
            'receipt.paper_width' => 80,
            'receipt.show_logo' => true,
            'receipt.header' => null,
            'receipt.footer' => 'شكرًا لزيارتكم، نتمنى رؤيتكم مجددًا',
            'receipt.auto_print' => true,
        ],
        'pos' => [
            'pos.allow_price_edit' => true,
            'pos.max_discount_percent' => '20',
            'pos.require_open_register' => true,
            'pos.default_payment_method' => 'cash',
            'pos.products_per_page' => 24,
        ],
        'inventory' => [
            'inventory.allow_negative_stock' => false,
            'inventory.costing_method' => 'weighted_average',
            'inventory.low_stock_alert' => true,
        ],
        'credit' => [
            'credit.enabled' => true,
            'credit.default_days' => 30,
            'credit.default_limit' => '0',
            'credit.allow_override' => true,
            'credit.block_on_overdue' => false,
        ],
        'barcode' => [
            'barcode.prefix' => '20',
            'barcode.label_size' => '50x25',
            'barcode.show_price' => true,
            'barcode.show_sku' => true,
            'barcode.show_store_name' => false,
        ],
        'numbering' => [
            'numbering.sale' => 'INV-',
            'numbering.purchase' => 'PO-',
            'numbering.purchase_receipt' => 'GRN-',
            'numbering.purchase_return' => 'PR-',
            'numbering.customer_payment' => 'RCPT-',
            'numbering.supplier_payment' => 'SP-',
            'numbering.sale_return' => 'RET-',
            'numbering.expense' => 'EXP-',
            'numbering.cash_register_session' => 'REG-',
            'numbering.stock_adjustment' => 'ADJ-',
            'numbering.stock_count' => 'CNT-',
            'numbering.padding' => 6,
        ],
    ],
];
