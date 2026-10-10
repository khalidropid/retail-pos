<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return array<string, string> */
    public static function eventLabels(): array
    {
        return [
            'login' => 'تسجيل دخول',
            'logout' => 'تسجيل خروج',
            'login_failed' => 'محاولة دخول فاشلة',
            'created' => 'إنشاء',
            'updated' => 'تعديل',
            'deleted' => 'حذف',
            'restored' => 'استعادة',
            'price_changed' => 'تغيير سعر',
            'barcode_generated' => 'توليد باركود',
            'stock_adjusted' => 'تسوية مخزون',
            'stock_counted' => 'اعتماد جرد',
            'sale_completed' => 'عملية بيع',
            'sale_cancelled' => 'إلغاء بيع',
            'sale_returned' => 'مرتجع مبيعات',
            'customer_payment' => 'دفعة عميل',
            'payment_reversed' => 'عكس دفعة',
            'credit_limit_changed' => 'تغيير الحد الائتماني',
            'credit_limit_overridden' => 'تجاوز الحد الائتماني',
            'register_opened' => 'فتح صندوق',
            'register_closed' => 'إغلاق صندوق',
            'cash_in' => 'إيداع نقدي',
            'cash_out' => 'سحب نقدي',
            'expense_created' => 'تسجيل مصروف',
            'expense_voided' => 'إلغاء مصروف',
            'purchase_received' => 'استلام مشتريات',
            'purchase_cancelled' => 'إلغاء أمر شراء',
            'purchase_returned' => 'مرتجع مشتريات',
            'supplier_payment' => 'دفعة لمورد',
            'supplier_payment_reversed' => 'عكس دفعة مورد',
            'user_changed' => 'تعديل مستخدم',
            'permissions_changed' => 'تغيير صلاحيات',
            'settings_changed' => 'تغيير الإعدادات',
        ];
    }

    public function getEventLabelAttribute(): string
    {
        return self::eventLabels()[$this->event] ?? $this->event;
    }
}
