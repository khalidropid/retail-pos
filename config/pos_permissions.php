<?php

/*
| Permission catalogue. Keys are permission names (checked in policies and
| services); values are the Arabic labels shown in the roles screen.
*/

return [
    'groups' => [
        'نقطة البيع والمبيعات' => [
            'pos.access' => 'الدخول إلى نقطة البيع',
            'sales.view' => 'عرض المبيعات',
            'sales.view_all' => 'عرض مبيعات جميع الكاشيرات',
            'sales.create' => 'إنشاء بيع',
            'sales.cancel' => 'إلغاء بيع',
            'sales.edit_price' => 'تعديل الأسعار عند البيع',
            'sales.apply_discount' => 'تطبيق خصم',
            'sales.credit' => 'بيع آجل',
            'sales.override_credit_limit' => 'تجاوز الحد الائتماني',
        ],
        'المرتجعات' => [
            'returns.view' => 'عرض المرتجعات',
            'returns.create' => 'إجراء مرتجع',
        ],
        'العملاء والذمم' => [
            'customers.view' => 'عرض العملاء',
            'customers.create' => 'إضافة عميل',
            'customers.update' => 'تعديل عميل',
            'customers.delete' => 'حذف عميل',
            'customers.update_credit_limit' => 'تعديل الحد الائتماني',
            'customers.view_debts' => 'مشاهدة ديون العملاء',
            'customer_payments.view' => 'عرض دفعات العملاء',
            'customer_payments.create' => 'تسجيل دفعة من عميل',
            'customer_payments.reverse' => 'عكس / إلغاء دفعة عميل',
            'customer_payments.verify' => 'تأكيد التحويلات البنكية',
        ],
        'المنتجات' => [
            'products.view' => 'عرض المنتجات',
            'products.create' => 'إضافة منتج',
            'products.update' => 'تعديل منتج',
            'products.update_price' => 'تعديل أسعار المنتجات',
            'products.delete' => 'حذف منتج',
            'products.view_cost' => 'مشاهدة التكلفة',
            'products.regenerate_barcode' => 'إعادة توليد الباركود',
            'catalog.manage' => 'إدارة التصنيفات والعلامات والوحدات',
        ],
        'المخزون' => [
            'inventory.view' => 'عرض المخزون وحركاته',
            'inventory.adjust' => 'تعديل المخزون (تسوية)',
            'inventory.count' => 'الجرد',
        ],
        'الموردون والمشتريات' => [
            'suppliers.view' => 'عرض الموردين',
            'suppliers.manage' => 'إدارة الموردين',
            'purchases.view' => 'عرض المشتريات',
            'purchases.create' => 'إنشاء وتعديل أوامر الشراء',
            'purchases.receive' => 'استلام المشتريات',
            'purchases.cancel' => 'إلغاء أوامر الشراء',
            'purchases.return' => 'مرتجع مشتريات',
            'supplier_payments.create' => 'تسجيل دفعة لمورد',
            'supplier_payments.reverse' => 'عكس دفعة مورد',
        ],
        'الصندوق والمصروفات' => [
            'cash_register.open' => 'فتح صندوق',
            'cash_register.close' => 'إغلاق صندوق',
            'cash_register.cash_in_out' => 'إيداع وسحب نقدي',
            'cash_register.view_all' => 'عرض جميع الصناديق',
            'cash_register.manage' => 'إدارة الصناديق',
            'expenses.view' => 'عرض المصروفات',
            'expenses.create' => 'تسجيل مصروف',
            'expenses.void' => 'إلغاء مصروف',
            'expense_categories.manage' => 'إدارة تصنيفات المصروفات',
        ],
        'التقارير' => [
            'reports.view' => 'مشاهدة التقارير',
            'reports.profit' => 'مشاهدة الأرباح',
            'dashboard.financials' => 'مشاهدة المؤشرات المالية في لوحة التحكم',
        ],
        'الإدارة' => [
            'users.manage' => 'إدارة المستخدمين',
            'roles.manage' => 'إدارة الأدوار والصلاحيات',
            'settings.manage' => 'إدارة الإعدادات',
            'payment_methods.manage' => 'إدارة طرق الدفع',
            'audit_logs.view' => 'مشاهدة سجل العمليات',
        ],
    ],

    'roles' => [
        'admin' => ['label' => 'مدير النظام', 'permissions' => '*'],
        'manager' => ['label' => 'مدير المتجر', 'permissions' => [
            'except' => ['users.manage', 'roles.manage'],
        ]],
        'cashier' => ['label' => 'كاشير', 'permissions' => [
            'pos.access', 'sales.view', 'sales.create', 'sales.apply_discount', 'sales.credit',
            'returns.view',
            'customers.view', 'customers.create',
            'customer_payments.view', 'customer_payments.create',
            'products.view',
            'cash_register.open', 'cash_register.close',
        ]],
        'inventory_clerk' => ['label' => 'أمين مخزن', 'permissions' => [
            'products.view', 'products.create', 'products.update', 'products.view_cost', 'catalog.manage',
            'inventory.view', 'inventory.adjust', 'inventory.count',
            'suppliers.view', 'suppliers.manage',
            'purchases.view', 'purchases.create', 'purchases.receive', 'purchases.return',
        ]],
    ],
];
