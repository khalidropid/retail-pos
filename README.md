# نظام نقاط البيع وإدارة المتجر (Retail POS)

نظام نقطة بيع وإدارة متجر باللغة العربية (RTL) مبني بـ Laravel 13 وFilament 5 وPostgreSQL 17،
مصمم لمتجر إكسسوارات ومستحضرات تجميل، ويصلح لأي متجر تجزئة.

يشمل: نقطة البيع بالباركود، المنتجات، المخزون ودفتر الحركات، الموردين والمشتريات والاستلام،
العملاء، البيع الآجل والذمم المدينة وكشوف الحساب وأعمار الديون، المرتجعات، الصندوق والمصروفات،
التقارير مع التصدير إلى Excel، سجل العمليات، وواجهة API جاهزة (`/api/v1`).

> النظام **ليس ERP**: لا يحتوي على موارد بشرية أو رواتب أو تصنيع أو محاسبة عامة كاملة.

---

## المتطلبات

| المكوّن | الإصدار |
|---|---|
| PHP | 8.4 أو أحدث، مع الإضافات: `pdo_pgsql`, `mbstring`, `intl`, `gd`, `zip`, `xml` |
| PostgreSQL | 17 (يُفضّل) — يحتاج إضافة `pg_trgm` (تأتي مع PostgreSQL) |
| Composer | 2.x |
| Node.js / npm | Node 20+ (لبناء ملفات الواجهة) |

لا يحتاج Docker.

## التثبيت

```bash
git clone <repo-url> retail-pos && cd retail-pos
composer install
cp .env.example .env
php artisan key:generate
```

أنشئ قاعدتي البيانات (التشغيل والاختبارات):

```bash
createdb retail_pos
createdb retail_pos_testing
```

عدّل بيانات الاتصال في `.env`:

```dotenv
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=retail_pos
DB_USERNAME=postgres
DB_PASSWORD=كلمة_المرور
```

ثم أنشئ الجداول مع البيانات التجريبية، واربط مجلد الصور، وابنِ ملفات الواجهة:

```bash
php artisan migrate --seed
php artisan storage:link
npm install
npm run build
```

> بدون بيانات تجريبية (متجر حقيقي فارغ): شغّل
> `php artisan db:seed --class=Database\\Seeders\\ProductionSeeder`
> بدلًا من `--seed`، ثم غيّر كلمة مرور المدير فورًا.

## التشغيل

```bash
php artisan serve
```

افتح: <http://localhost:8000> (يحوّلك إلى لوحة التحكم `/admin`).
نقطة البيع: `/admin/pos`.

## الحسابات التجريبية

كلمة المرور لجميع الحسابات: `password` — **غيّرها قبل أي استخدام حقيقي.**

| الدور | البريد | ما يستطيعه |
|---|---|---|
| مدير النظام | `admin@pos.test` | كل الصلاحيات |
| مدير المتجر | `manager@pos.test` | كل شيء عدا إدارة المستخدمين والأدوار |
| كاشير | `cashier@pos.test` | البيع (نقدي وآجل)، تسجيل دفعات العملاء، فتح/إغلاق صندوقه |
| أمين مخزن | `clerk@pos.test` | المنتجات، المخزون، الجرد، الموردين، المشتريات والاستلام |

البيانات التجريبية تغطي حوالي 100 يوم: مبيعات نقدية وبالبطاقة، مبيعات آجلة، دفعات جزئية وكاملة،
دفعة واحدة تسدد فاتورتين، ديون متأخرة في كل فئات أعمار الديون، مرتجعات، مشتريات (مستلمة/جزئية/مطلوبة)،
مصروفات، وجلسات صندوق مغلقة ببعض الفروقات. يبقى صندوق الكاشير مفتوحًا لتجربة البيع مباشرة.

## الاختبارات

```bash
php artisan test
```

تعمل الاختبارات على قاعدة `retail_pos_testing` (PostgreSQL حقيقية، لا SQLite) لأن النظام يعتمد على
قيود CHECK والفهارس الجزئية وقفل الصفوف في PostgreSQL.

## الإعداد للإنتاج

- في `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true` (مع HTTPS).
- `php artisan optimize` بعد كل تحديث.
- لا تضع `.env` في Git (مستبعد مسبقًا في `.gitignore`).
- خذ نسخة احتياطية دورية من قاعدة البيانات (`pg_dump`).

---

## البنية

- **Services** (`app/Services`): كل منطق الأعمال. واجهة Filament والـ API تستدعيان الخدمات نفسها،
  والصلاحيات تُفحص داخل الخدمات وليس في الواجهة فقط.
  `SaleService`, `CustomerPaymentService`, `ReceivableService`, `ReturnService`, `InventoryService`,
  `PurchaseService`, `SupplierPaymentService`, `CashRegisterService`, `ExpenseService`,
  `CustomerStatementService`, `ReportService`, `DashboardService`.
- **دفاتر غير قابلة للتعديل**: `stock_movements`, `customer_ledger_entries`, `supplier_ledger_entries`,
  `cash_movements`. أي تصحيح يتم بحركة عكسية جديدة.
- **أرصدة مخزّنة يكتبها مصدر واحد فقط**: `products.stock_quantity` (InventoryService)،
  `customers.balance` (CustomerAccountService)، `suppliers.balance` (SupplierAccountService).
- **المال**: أعمدة `NUMERIC` فقط، والحساب بـ `brick/math` (فئة `App\Support\Money`) — لا `float`.
- **التزامن**: كل العمليات الحساسة داخل `DB::transaction()` مع `SELECT ... FOR UPDATE`،
  وتُقفل المنتجات بترتيب المعرّف لتفادي الـ deadlock.
- **حماية في قاعدة البيانات** (حتى لو تجاوز أحد الكود):
  - `balance_due = total - returned - paid + refunded` للفواتير.
  - رصيد العميل لا يكون سالبًا.
  - الكمية المرتجعة ≤ المباعة، والمستلمة ≤ المطلوبة.
  - صندوق مفتوح واحد لكل مستخدم ولكل صندوق.
  - باركود وSKU فريدان، وأرقام المستندات فريدة.
- **الترقيم**: جدول `document_sequences` مقفول أثناء الحجز؛ البادئات من الإعدادات (INV-, RCPT-, PO- …).

## قواعد الآجل والذمم

- البيع الآجل يتطلب عميلًا، ويُحسب تاريخ الاستحقاق من مدة سداد العميل (أو الافتراضي في الإعدادات).
- الحد الائتماني: `الدين الحالي + المبلغ الآجل الجديد ≤ الحد`. حد = 0 يعني لا بيع آجل.
  التجاوز يحتاج صلاحية «تجاوز الحد الائتماني» + تفعيله في الإعدادات، ويُسجَّل في سجل العمليات.
- الدفعة: لا تتجاوز دين العميل، توزَّع تلقائيًا على الأقدم استحقاقًا أو يدويًا (يجب أن يساوي مجموع التوزيع مبلغ الدفعة).
- لا تُحذف الدفعات: «عكس الدفعة» يعيد الدين ويُخرج النقد من الصندوق ويُسجَّل مع السبب.
- المرتجع يخفض المتبقي على الفاتورة أولًا ثم يُرد الباقي للعميل.

## الطباعة

- إيصال حراري 58/80 مم: `/print/sales/{id}/receipt`
- إيصال سداد: `/print/payments/{id}/receipt`
- كشف حساب عميل: `/print/customers/{id}/statement` (أضف `?format=pdf` لملف PDF عربي)
- التقارير: زر «طباعة» و«تصدير Excel» في كل صفحة تقارير.

## API

المصادقة عبر Sanctum:

```bash
curl -X POST http://localhost:8000/api/v1/auth/token \
  -H "Accept: application/json" \
  -d email=cashier@pos.test -d password=password -d device_name=tablet
```

ثم أرسل الرمز في `Authorization: Bearer <token>`.

| الطريقة | المسار | الوصف |
|---|---|---|
| POST / DELETE | `/api/v1/auth/token` | إصدار / إلغاء رمز |
| GET | `/api/v1/me` | المستخدم وصلاحياته |
| GET | `/api/v1/products?search=` | المنتجات (مع ترقيم صفحات) |
| GET | `/api/v1/products/code/{barcode}` | بحث بالباركود أو SKU |
| GET | `/api/v1/customers`, `/customers/{id}` | العملاء |
| GET | `/api/v1/customers/{id}/statement` | كشف الحساب |
| POST | `/api/v1/customers/{id}/payments` | تسجيل دفعة |
| GET / POST | `/api/v1/sales` | المبيعات / إنشاء بيع |
| GET | `/api/v1/sales/{id}` | تفاصيل فاتورة |

حد الطلبات: 120 طلب/دقيقة للمستخدم، و5 محاولات دخول/دقيقة.
