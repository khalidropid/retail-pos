<?php

namespace App\Filament\Pages;

use App\Services\AuditLogger;
use App\Services\SettingsService;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * @property-read Schema $form
 */
class Settings extends Page
{
    protected string $view = 'filament.pages.settings';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCog6Tooth;

    protected static string|UnitEnum|null $navigationGroup = 'الإدارة';

    protected static ?string $title = 'الإعدادات';

    protected static ?int $navigationSort = 10;

    /** @var array<string, mixed> */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        return auth()->user()?->can('settings.manage') ?? false;
    }

    public function mount(): void
    {
        $service = app(SettingsService::class);
        $state = [];
        foreach (array_keys($service->defaults()) as $key) {
            $state[self::field($key)] = $service->get($key);
        }

        $this->form->fill($state);
    }

    public static function field(string $key): string
    {
        return str_replace('.', '__', $key);
    }

    public function form(Schema $schema): Schema
    {
        $f = fn (string $key) => self::field($key);

        return $schema->statePath('data')->components([
            Tabs::make('settings')->persistTabInQueryString()->tabs([
                Tab::make('بيانات المتجر')->icon(Heroicon::OutlinedBuildingStorefront)->columns(2)->schema([
                    TextInput::make($f('store.name'))->label('اسم المتجر')->required()->maxLength(150),
                    TextInput::make($f('store.phone'))->label('رقم الهاتف')->tel()->maxLength(30),
                    TextInput::make($f('store.email'))->label('البريد الإلكتروني')->email()->maxLength(150),
                    TextInput::make($f('store.tax_number'))->label('الرقم الضريبي')->maxLength(50),
                    Textarea::make($f('store.address'))->label('العنوان')->rows(2)->columnSpanFull(),
                    FileUpload::make($f('store.logo'))->label('الشعار')->image()->disk('public')
                        ->directory('settings')->maxSize(1024)->imageEditor(),
                    Select::make($f('app.timezone'))->label('المنطقة الزمنية')->required()->searchable()
                        ->options(fn () => array_combine(timezone_identifiers_list(), timezone_identifiers_list())),
                ]),
                Tab::make('العملة')->icon(Heroicon::OutlinedBanknotes)->columns(3)->schema([
                    TextInput::make($f('currency.code'))->label('رمز العملة')->required()->maxLength(5),
                    TextInput::make($f('currency.symbol'))->label('رمز العرض')->required()->maxLength(10),
                    Select::make($f('currency.decimals'))->label('المنازل العشرية للعرض')
                        ->options([0 => '0', 1 => '1', 2 => '2'])->required(),
                ]),
                Tab::make('الضرائب')->icon(Heroicon::OutlinedReceiptPercent)->columns(2)->schema([
                    Toggle::make($f('tax.enabled'))->label('تفعيل الضريبة'),
                    Toggle::make($f('tax.prices_include_tax'))->label('الأسعار شاملة الضريبة'),
                    TextInput::make($f('tax.default_rate'))->label('نسبة الضريبة الافتراضية %')
                        ->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make($f('tax.label'))->label('اسم الضريبة في الفواتير')->maxLength(50),
                ]),
                Tab::make('الفواتير والإيصالات')->icon(Heroicon::OutlinedPrinter)->columns(2)->schema([
                    Textarea::make($f('invoice.footer'))->label('تذييل الفاتورة')->rows(2),
                    Textarea::make($f('invoice.terms'))->label('شروط البيع')->rows(2),
                    Toggle::make($f('invoice.show_tax_number'))->label('إظهار الرقم الضريبي'),
                    Select::make($f('receipt.paper_width'))->label('عرض ورق الإيصال (مم)')
                        ->options([58 => '58 مم', 80 => '80 مم'])->required(),
                    Toggle::make($f('receipt.show_logo'))->label('إظهار الشعار في الإيصال'),
                    Toggle::make($f('receipt.auto_print'))->label('طباعة تلقائية بعد البيع'),
                    Textarea::make($f('receipt.header'))->label('ترويسة الإيصال')->rows(2),
                    Textarea::make($f('receipt.footer'))->label('رسالة الشكر في الإيصال')->rows(2),
                ]),
                Tab::make('نقطة البيع')->icon(Heroicon::OutlinedComputerDesktop)->columns(2)->schema([
                    Toggle::make($f('pos.allow_price_edit'))->label('السماح بتعديل السعر (لمن لديه الصلاحية)'),
                    Toggle::make($f('pos.require_open_register'))->label('اشتراط صندوق مفتوح للبيع'),
                    TextInput::make($f('pos.max_discount_percent'))->label('أقصى نسبة خصم %')
                        ->numeric()->minValue(0)->maxValue(100)->required(),
                    TextInput::make($f('pos.products_per_page'))->label('عدد المنتجات في الشبكة')
                        ->integer()->minValue(6)->maxValue(100)->required(),
                ]),
                Tab::make('المخزون')->icon(Heroicon::OutlinedCube)->columns(2)->schema([
                    Toggle::make($f('inventory.allow_negative_stock'))->label('السماح بالمخزون السالب'),
                    Toggle::make($f('inventory.low_stock_alert'))->label('تنبيه المخزون المنخفض'),
                    Select::make($f('inventory.costing_method'))->label('طريقة احتساب التكلفة')->required()
                        ->options(['weighted_average' => 'المتوسط المرجح', 'last_cost' => 'آخر تكلفة شراء']),
                ]),
                Tab::make('الآجل')->icon(Heroicon::OutlinedCreditCard)->columns(2)->schema([
                    Toggle::make($f('credit.enabled'))->label('تفعيل البيع الآجل'),
                    Toggle::make($f('credit.allow_override'))->label('السماح بتجاوز الحد الائتماني لمن لديه الصلاحية'),
                    Toggle::make($f('credit.block_on_overdue'))->label('منع البيع الآجل لعميل لديه ديون متأخرة'),
                    TextInput::make($f('credit.default_days'))->label('مدة السداد الافتراضية (يوم)')
                        ->integer()->minValue(0)->maxValue(365)->required(),
                    TextInput::make($f('credit.default_limit'))->label('الحد الائتماني الافتراضي للعملاء الجدد')
                        ->numeric()->minValue(0)->required(),
                ]),
                Tab::make('الباركود')->icon(Heroicon::OutlinedQrCode)->columns(2)->schema([
                    TextInput::make($f('barcode.prefix'))->label('بادئة الباركود الداخلي (رقمان)')->required()
                        ->regex('/^\d{2}$/')->helperText('يُفضّل 20-29 (نطاق GS1 للاستخدام الداخلي في المتجر)'),
                    Select::make($f('barcode.label_size'))->label('مقاس الملصق الافتراضي')->required()
                        ->options(collect(config('barcode.label_sizes'))->map(fn ($s) => $s['label'])),
                    Toggle::make($f('barcode.show_price'))->label('إظهار السعر على الملصق'),
                    Toggle::make($f('barcode.show_sku'))->label('إظهار رمز SKU على الملصق'),
                    Toggle::make($f('barcode.show_store_name'))->label('إظهار اسم المتجر على الملصق'),
                ]),
                Tab::make('النسخ الاحتياطي')->icon(Heroicon::OutlinedCircleStack)->columns(2)
                    ->visible(fn () => auth()->user()?->can('backups.manage'))->schema([
                        Toggle::make($f('backup.schedule_enabled'))->label('تفعيل النسخ الاحتياطي اليومي التلقائي')
                            ->helperText('يتطلب تشغيل مجدول النظام على الخادم (راجع دليل التشغيل).'),
                        TextInput::make($f('backup.schedule_time'))->label('وقت النسخ اليومي')->required()
                            ->regex('/^([01]\d|2[0-3]):[0-5]\d$/')->placeholder('02:00'),
                        TextInput::make($f('backup.retention_days'))->label('مدة الاحتفاظ بالنسخ التلقائية (يوم)')
                            ->integer()->minValue(1)->maxValue(3650)->required(),
                        Toggle::make($f('backup.include_files'))->label('تضمين صور المنتجات والشعار في النسخة'),
                    ]),
                Tab::make('الترقيم')->icon(Heroicon::OutlinedHashtag)->columns(3)->schema([
                    TextInput::make($f('numbering.sale'))->label('بادئة فواتير البيع')->required()->maxLength(10),
                    TextInput::make($f('numbering.purchase'))->label('بادئة أوامر الشراء')->required()->maxLength(10),
                    TextInput::make($f('numbering.purchase_receipt'))->label('بادئة سندات الاستلام')->required()->maxLength(10),
                    TextInput::make($f('numbering.purchase_return'))->label('بادئة مرتجع المشتريات')->required()->maxLength(10),
                    TextInput::make($f('numbering.customer_payment'))->label('بادئة سندات القبض')->required()->maxLength(10),
                    TextInput::make($f('numbering.supplier_payment'))->label('بادئة دفعات الموردين')->required()->maxLength(10),
                    TextInput::make($f('numbering.sale_return'))->label('بادئة المرتجعات')->required()->maxLength(10),
                    TextInput::make($f('numbering.expense'))->label('بادئة المصروفات')->required()->maxLength(10),
                    TextInput::make($f('numbering.cash_register_session'))->label('بادئة جلسات الصندوق')->required()->maxLength(10),
                    TextInput::make($f('numbering.stock_adjustment'))->label('بادئة تسويات المخزون')->required()->maxLength(10),
                    TextInput::make($f('numbering.stock_count'))->label('بادئة الجرد')->required()->maxLength(10),
                    TextInput::make($f('numbering.padding'))->label('عدد خانات الرقم')->integer()->minValue(3)->maxValue(10)->required(),
                ]),
            ]),
        ]);
    }

    public function save(): void
    {
        abort_unless(static::canAccess(), 403);

        $state = $this->form->getState();
        $service = app(SettingsService::class);
        $values = [];
        foreach (array_keys($service->defaults()) as $key) {
            if (array_key_exists(self::field($key), $state)) {
                $values[$key] = $state[self::field($key)];
            }
        }

        $before = $service->setMany($values);
        $changedOld = [];
        $changedNew = [];
        foreach ($values as $key => $value) {
            if ($before[$key] != $value) {
                $changedOld[$key] = $before[$key];
                $changedNew[$key] = $value;
            }
        }

        if ($changedNew !== []) {
            app(AuditLogger::class)->log('settings_changed', null, $changedOld, $changedNew);
        }

        Notification::make()->success()->title('تم حفظ الإعدادات بنجاح')->send();
    }

    protected function getFormActions(): array
    {
        return [Action::make('save')->label('حفظ الإعدادات')->submit('save')];
    }
}
