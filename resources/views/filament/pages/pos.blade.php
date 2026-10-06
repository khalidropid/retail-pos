<x-filament-panels::page>
    @php($config = $this->getConfig())

    <script>
        window.posApp = function (config) {
            return {
                config,
                term: '',
                categoryId: null,
                products: [],
                loading: false,
                cart: [],
                invoiceDiscount: 0,
                saleType: 'cash',
                paymentMethodId: (config.paymentMethods.find(m => m.isCash) || config.paymentMethods[0] || {}).id,
                tendered: '',
                downPayment: 0,
                dueDate: '',
                overrideCredit: false,
                notes: '',
                customer: null,
                customerTerm: '',
                customerResults: [],
                newCustomer: { open: false, name: '', phone: '' },
                busy: false,
                error: '',
                lastSale: null,

                init() {
                    this.loadProducts();
                    this.$nextTick(() => this.$refs.scan?.focus());
                    window.addEventListener('keydown', (e) => {
                        if (e.key === 'F2') { e.preventDefault(); this.$refs.scan?.focus(); }
                        if (e.key === 'F4') { e.preventDefault(); this.$refs.customerSearch?.focus(); }
                        if (e.key === 'F9') { e.preventDefault(); this.checkout(); }
                    });
                },

                // ---------- formatting & math (preview only; server is authoritative)
                round(v, d = 2) { const f = Math.pow(10, d); return Math.round((Number(v) + Number.EPSILON) * f) / f; },
                money(v) {
                    const d = this.config.currency.decimals;
                    return Number(v || 0).toLocaleString('en-US', { minimumFractionDigits: d, maximumFractionDigits: d }) + ' ' + this.config.currency.symbol;
                },
                lineGross(l) { return this.round(l.price * l.quantity); },
                get subtotal() { return this.round(this.cart.reduce((s, l) => s + this.lineGross(l), 0)); },
                get lineDiscounts() { return this.round(this.cart.reduce((s, l) => s + Number(l.discount || 0), 0)); },
                get netBeforeInvoiceDiscount() { return this.round(this.subtotal - this.lineDiscounts); },
                get totals() {
                    const inv = Number(this.invoiceDiscount || 0);
                    const netSum = this.netBeforeInvoiceDiscount;
                    let tax = 0, total = 0, allocated = 0;
                    this.cart.forEach((l, i) => {
                        const net0 = this.lineGross(l) - Number(l.discount || 0);
                        const share = i === this.cart.length - 1 ? this.round(inv - allocated) : (netSum > 0 ? this.round(inv * net0 / netSum) : 0);
                        allocated += share;
                        const net = this.round(net0 - share);
                        const rate = this.config.tax.enabled ? Number(l.taxRate || 0) : 0;
                        if (!rate) { total += net; return; }
                        if (this.config.tax.inclusive) { const ex = this.round(net / (1 + rate / 100)); tax += this.round(net - ex); total += net; }
                        else { const t = this.round(net * rate / 100); tax += t; total += net + t; }
                    });
                    return { discount: this.round(this.lineDiscounts + inv), tax: this.round(tax), total: this.round(total) };
                },
                get itemsCount() { return this.cart.reduce((s, l) => s + Number(l.quantity), 0); },
                get change() { return this.tendered === '' ? 0 : this.round(Number(this.tendered) - this.totals.total); },
                get creditAmount() { return this.round(this.totals.total - Number(this.downPayment || 0)); },
                get exceedsCredit() {
                    if (this.saleType !== 'credit' || !this.customer || this.customer.creditLimit === null) return false;
                    return this.customer.balance + this.creditAmount > this.customer.creditLimit;
                },
                get selectedMethod() { return this.config.paymentMethods.find(m => m.id == this.paymentMethodId); },

                // ---------- products
                async loadProducts() {
                    this.loading = true;
                    try { this.products = await this.$wire.searchProducts(this.term, this.categoryId); }
                    finally { this.loading = false; }
                },
                async scan() {
                    const code = this.term.trim();
                    if (!code) return;
                    const product = await this.$wire.findByCode(code);
                    if (product) { this.add(product); this.term = ''; this.loadProducts(); return; }
                    await this.loadProducts();
                    if (this.products.length === 1) { this.add(this.products[0]); this.term = ''; this.loadProducts(); }
                    else if (this.products.length === 0) { this.flash('لم يتم العثور على منتج بهذا الرمز: ' + code); }
                },
                selectCategory(id) { this.categoryId = id; this.loadProducts(); },
                add(p) {
                    this.error = '';
                    const line = this.cart.find(l => l.id === p.id);
                    if (line) { line.quantity = this.round(Number(line.quantity) + 1, 3); }
                    else { this.cart.push({ ...p, quantity: 1, discount: 0, priceEdited: false, listPrice: p.price }); }
                    if (p.stock !== null && (line ? line.quantity : 1) > p.stock) {
                        this.flash('تنبيه: الكمية المطلوبة من "' + p.name + '" أكبر من المتوفر (' + p.stock + ')');
                    }
                    this.$nextTick(() => this.$refs.scan?.focus());
                },
                inc(l) { l.quantity = this.round(Number(l.quantity) + 1, 3); },
                dec(l) { if (l.quantity > 1) l.quantity = this.round(Number(l.quantity) - 1, 3); },
                remove(i) { this.cart.splice(i, 1); },
                normalizeQty(l) {
                    let q = Number(l.quantity);
                    if (!l.allowDecimal) q = Math.round(q);
                    l.quantity = q > 0 ? this.round(q, 3) : 1;
                },
                priceChanged(l) { l.priceEdited = Number(l.price) !== Number(l.listPrice); },
                clearCart() {
                    this.cart = []; this.invoiceDiscount = 0; this.tendered = ''; this.downPayment = 0;
                    this.customer = null; this.saleType = 'cash'; this.overrideCredit = false; this.notes = ''; this.error = '';
                },

                // ---------- customers
                async findCustomers() {
                    if (this.customerTerm.trim().length < 1) { this.customerResults = []; return; }
                    this.customerResults = await this.$wire.searchCustomers(this.customerTerm);
                },
                pickCustomer(c) { this.customer = c; this.customerResults = []; this.customerTerm = ''; this.dueDate = c.dueDate; },
                async saveNewCustomer() {
                    const r = await this.$wire.createCustomer(this.newCustomer.name, this.newCustomer.phone || null);
                    if (!r.ok) { this.flash(r.message); return; }
                    this.pickCustomer(r.customer);
                    this.newCustomer = { open: false, name: '', phone: '' };
                },
                setType(t) {
                    this.saleType = t;
                    if (t === 'credit') { this.downPayment = 0; this.tendered = ''; if (this.customer) this.dueDate = this.customer.dueDate; }
                },

                // ---------- checkout
                flash(msg) { this.error = msg; },
                async checkout() {
                    if (this.busy) return;
                    this.error = '';
                    if (!this.cart.length) return this.flash('السلة فارغة.');
                    if (this.config.requireRegister && !this.config.register) return this.flash('يجب فتح صندوق قبل البيع.');
                    if (this.saleType === 'credit' && !this.customer) return this.flash('يجب اختيار العميل في البيع الآجل.');
                    if (this.saleType === 'cash' && this.selectedMethod?.isCash && this.tendered !== '' && this.change < 0) return this.flash('المبلغ المستلم أقل من الإجمالي.');

                    this.busy = true;
                    try {
                        const r = await this.$wire.checkout({
                            items: this.cart.map(l => ({ id: l.id, quantity: l.quantity, price: l.price, priceEdited: l.priceEdited, discount: l.discount || 0 })),
                            saleType: this.saleType,
                            customerId: this.customer?.id ?? null,
                            paymentMethodId: this.paymentMethodId,
                            tendered: this.saleType === 'cash' && this.selectedMethod?.isCash ? this.tendered : null,
                            downPayment: this.downPayment || 0,
                            invoiceDiscount: this.invoiceDiscount || 0,
                            dueDate: this.saleType === 'credit' ? this.dueDate : null,
                            overrideCredit: this.overrideCredit,
                            notes: this.notes,
                        });
                        if (!r.ok) { this.flash(r.message); return; }
                        this.lastSale = r.sale;
                        if (this.config.autoPrint) window.open(r.receiptUrl, 'receipt', 'width=420,height=700');
                        this.lastSale.receiptUrl = r.receiptUrl;
                        this.clearCart();
                        this.loadProducts();
                    } finally {
                        this.busy = false;
                        this.$nextTick(() => this.$refs.scan?.focus());
                    }
                },
            };
        };
    </script>

    <div x-data="posApp(@js($config))" class="pos-root grid grid-cols-1 gap-4 xl:grid-cols-12" dir="rtl">

        {{-- Products --}}
        <section class="space-y-3 xl:col-span-7">
            <template x-if="config.requireRegister && !config.register">
                <div class="rounded-xl border border-warning-300 bg-warning-50 p-3 text-sm text-warning-800 dark:border-warning-700 dark:bg-warning-950 dark:text-warning-200">
                    لا يوجد صندوق مفتوح.
                    <a :href="config.registerUrl" class="font-bold underline">افتح صندوقًا</a>
                    للبدء بالبيع.
                </div>
            </template>
            <template x-if="config.register">
                <div class="text-xs text-gray-500 dark:text-gray-400">
                    الصندوق: <span class="font-semibold" x-text="config.register.name"></span>
                    — الجلسة <span x-text="config.register.number"></span>
                </div>
            </template>

            <div class="flex gap-2">
                <input x-ref="scan" x-model="term" type="text" autocomplete="off"
                       @keydown.enter.prevent="scan()" @input.debounce.300ms="loadProducts()"
                       placeholder="امسح الباركود أو ابحث بالاسم / SKU  (F2)"
                       class="w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-lg shadow-sm focus:border-primary-500 focus:ring-primary-500 dark:border-gray-700 dark:bg-gray-900 dark:text-white">
            </div>

            <div class="flex flex-wrap gap-2">
                <button type="button" @click="selectCategory(null)"
                        :class="categoryId === null ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 dark:bg-gray-800 dark:text-gray-200'"
                        class="rounded-full border border-gray-200 px-3 py-1 text-sm dark:border-gray-700">الكل</button>
                <template x-for="c in config.categories" :key="c.id">
                    <button type="button" @click="selectCategory(c.id)" x-text="c.name"
                            :class="categoryId === c.id ? 'bg-primary-600 text-white' : 'bg-white text-gray-700 dark:bg-gray-800 dark:text-gray-200'"
                            class="rounded-full border border-gray-200 px-3 py-1 text-sm dark:border-gray-700"></button>
                </template>
            </div>

            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-4">
                <template x-for="p in products" :key="p.id">
                    <button type="button" @click="add(p)"
                            class="flex flex-col rounded-xl border border-gray-200 bg-white p-3 text-start shadow-sm transition hover:border-primary-500 hover:shadow dark:border-gray-700 dark:bg-gray-900">
                        <div class="mb-2 flex h-16 items-center justify-center overflow-hidden rounded-lg bg-gray-50 dark:bg-gray-800">
                            <template x-if="p.image"><img :src="p.image" class="h-full object-contain" alt=""></template>
                            <template x-if="!p.image"><span class="text-2xl text-gray-300">📦</span></template>
                        </div>
                        <div class="line-clamp-2 text-sm font-semibold text-gray-800 dark:text-gray-100" x-text="p.name"></div>
                        <div class="mt-auto flex items-center justify-between pt-2">
                            <span class="text-sm font-bold text-primary-600" x-text="money(p.price)"></span>
                            <span class="text-xs" :class="p.stock !== null && p.stock <= 0 ? 'text-danger-600' : 'text-gray-500'"
                                  x-text="p.stock === null ? '' : ('المتوفر ' + p.stock)"></span>
                        </div>
                    </button>
                </template>
            </div>
            <div x-show="!loading && products.length === 0" class="py-8 text-center text-gray-500">لا توجد منتجات مطابقة</div>
            <div x-show="loading" class="py-4 text-center text-sm text-gray-400">جارِ البحث…</div>
        </section>

        {{-- Cart & checkout --}}
        <section class="xl:col-span-5">
            <div class="sticky top-4 space-y-3 rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-gray-700 dark:bg-gray-900">

                {{-- Customer --}}
                <div class="space-y-2">
                    <template x-if="!customer">
                        <div class="relative">
                            <input x-ref="customerSearch" x-model="customerTerm" @input.debounce.300ms="findCustomers()" type="text"
                                   placeholder="ابحث عن عميل بالاسم أو الهاتف (F4) — اختياري للبيع النقدي"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <div x-show="customerResults.length" class="absolute z-20 mt-1 max-h-60 w-full overflow-auto rounded-lg border border-gray-200 bg-white shadow-lg dark:border-gray-700 dark:bg-gray-800">
                                <template x-for="c in customerResults" :key="c.id">
                                    <button type="button" @click="pickCustomer(c)" class="block w-full px-3 py-2 text-start text-sm hover:bg-gray-50 dark:hover:bg-gray-700">
                                        <span class="font-semibold" x-text="c.name"></span>
                                        <span class="text-gray-500" x-text="c.phone ? ' — ' + c.phone : ''"></span>
                                        <span x-show="c.balance > 0" class="text-danger-600" x-text="' — دين: ' + money(c.balance)"></span>
                                    </button>
                                </template>
                            </div>
                            <button type="button" x-show="config.can.createCustomer" @click="newCustomer.open = !newCustomer.open"
                                    class="mt-1 text-xs text-primary-600 underline">+ عميل جديد</button>
                            <div x-show="newCustomer.open" class="mt-2 flex gap-2">
                                <input x-model="newCustomer.name" placeholder="الاسم" class="w-1/2 rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <input x-model="newCustomer.phone" placeholder="الهاتف" class="w-1/3 rounded-lg border border-gray-300 px-2 py-1 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <button type="button" @click="saveNewCustomer()" class="rounded-lg bg-primary-600 px-3 text-sm text-white">حفظ</button>
                            </div>
                        </div>
                    </template>
                    <template x-if="customer">
                        <div class="rounded-lg bg-gray-50 p-2 text-sm dark:bg-gray-800">
                            <div class="flex items-center justify-between">
                                <span class="font-bold" x-text="customer.name"></span>
                                <button type="button" @click="customer = null" class="text-xs text-danger-600">إزالة</button>
                            </div>
                            <template x-if="customer.balance !== null">
                                <div class="mt-1 grid grid-cols-2 gap-x-3 text-xs text-gray-600 dark:text-gray-300">
                                    <span>الدين الحالي: <b class="text-danger-600" x-text="money(customer.balance)"></b></span>
                                    <span>الحد الائتماني: <b x-text="money(customer.creditLimit)"></b></span>
                                    <span>الرصيد المتاح: <b class="text-success-600" x-text="money(customer.available)"></b></span>
                                    <span x-show="customer.overdue > 0">متأخر: <b class="text-danger-600" x-text="money(customer.overdue)"></b></span>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>

                {{-- Cart lines --}}
                <div class="max-h-[38vh] divide-y divide-gray-100 overflow-auto dark:divide-gray-800">
                    <template x-for="(l, i) in cart" :key="l.id">
                        <div class="py-2">
                            <div class="flex items-start justify-between gap-2">
                                <div class="text-sm font-semibold text-gray-800 dark:text-gray-100" x-text="l.name"></div>
                                <button type="button" @click="remove(i)" class="text-danger-600" title="حذف">✕</button>
                            </div>
                            <div class="mt-1 flex flex-wrap items-center gap-2 text-sm">
                                <div class="flex items-center rounded-lg border border-gray-300 dark:border-gray-700">
                                    <button type="button" @click="dec(l)" class="px-2">−</button>
                                    <input type="number" :step="l.allowDecimal ? '0.001' : '1'" min="0" x-model.number="l.quantity" @change="normalizeQty(l)"
                                           class="w-16 border-0 bg-transparent p-1 text-center text-sm dark:text-white">
                                    <button type="button" @click="inc(l)" class="px-2">+</button>
                                </div>
                                <span>×</span>
                                <input type="number" min="0" step="any" x-model.number="l.price" @change="priceChanged(l)" :disabled="!config.can.editPrice"
                                       class="w-24 rounded-lg border border-gray-300 p-1 text-sm disabled:bg-gray-50 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <template x-if="config.can.discount">
                                    <input type="number" min="0" step="any" x-model.number="l.discount" placeholder="خصم"
                                           class="w-20 rounded-lg border border-gray-300 p-1 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                </template>
                                <span class="ms-auto font-bold" x-text="money(lineGross(l) - (l.discount || 0))"></span>
                            </div>
                        </div>
                    </template>
                    <div x-show="!cart.length" class="py-6 text-center text-sm text-gray-400">السلة فارغة — امسح منتجًا للبدء</div>
                </div>

                {{-- Totals --}}
                <div class="space-y-1 border-t border-gray-200 pt-2 text-sm dark:border-gray-700">
                    <div class="flex justify-between"><span>المجموع (<span x-text="itemsCount"></span> قطعة)</span><span x-text="money(subtotal)"></span></div>
                    <div class="flex items-center justify-between" x-show="config.can.discount">
                        <span>خصم على الفاتورة</span>
                        <input type="number" min="0" step="any" x-model.number="invoiceDiscount"
                               class="w-28 rounded-lg border border-gray-300 p-1 text-sm dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                    </div>
                    <div class="flex justify-between text-danger-600" x-show="totals.discount > 0"><span>إجمالي الخصم</span><span x-text="'- ' + money(totals.discount)"></span></div>
                    <div class="flex justify-between" x-show="config.tax.enabled && totals.tax > 0">
                        <span x-text="config.tax.inclusive ? 'الضريبة (ضمن السعر)' : 'الضريبة'"></span><span x-text="money(totals.tax)"></span>
                    </div>
                    <div class="flex justify-between text-2xl font-extrabold text-gray-900 dark:text-white"><span>الإجمالي</span><span x-text="money(totals.total)"></span></div>
                </div>

                {{-- Payment type --}}
                <div class="grid grid-cols-4 gap-2">
                    <template x-for="m in config.paymentMethods.filter(m => ['cash','card','bank_transfer'].includes(m.code))" :key="m.id">
                        <button type="button" @click="setType('cash'); paymentMethodId = m.id"
                                :class="saleType === 'cash' && paymentMethodId == m.id ? 'bg-primary-600 text-white border-primary-600' : 'bg-white dark:bg-gray-800 dark:text-gray-200'"
                                class="rounded-lg border border-gray-300 py-2 text-sm font-semibold dark:border-gray-700" x-text="m.name"></button>
                    </template>
                    <button type="button" x-show="config.can.credit" @click="setType('credit')"
                            :class="saleType === 'credit' ? 'bg-warning-500 text-white border-warning-500' : 'bg-white dark:bg-gray-800 dark:text-gray-200'"
                            class="rounded-lg border border-gray-300 py-2 text-sm font-semibold dark:border-gray-700">آجل</button>
                </div>

                <template x-if="saleType === 'cash' && selectedMethod?.isCash">
                    <div class="flex items-center justify-between gap-2 text-sm">
                        <label>المبلغ المستلم</label>
                        <input type="number" min="0" step="any" x-model="tendered" placeholder="اختياري"
                               class="w-32 rounded-lg border border-gray-300 p-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        <span>الباقي: <b :class="change < 0 ? 'text-danger-600' : 'text-success-600'" x-text="money(change)"></b></span>
                    </div>
                </template>

                <template x-if="saleType === 'credit'">
                    <div class="space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-2 text-sm dark:border-warning-700 dark:bg-warning-950">
                        <div x-show="!customer" class="font-semibold text-danger-600">اختر العميل للبيع الآجل</div>
                        <div class="flex items-center justify-between gap-2">
                            <label>دفعة مقدمة</label>
                            <input type="number" min="0" step="any" x-model.number="downPayment"
                                   class="w-28 rounded-lg border border-gray-300 p-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                            <select x-model="paymentMethodId" x-show="downPayment > 0" class="rounded-lg border border-gray-300 p-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                                <template x-for="m in config.paymentMethods" :key="m.id"><option :value="m.id" x-text="m.name"></option></template>
                            </select>
                        </div>
                        <div class="flex items-center justify-between gap-2">
                            <label>تاريخ الاستحقاق</label>
                            <input type="date" x-model="dueDate" class="rounded-lg border border-gray-300 p-1 dark:border-gray-700 dark:bg-gray-800 dark:text-white">
                        </div>
                        <div class="flex justify-between font-semibold"><span>المبلغ الآجل</span><span x-text="money(creditAmount)"></span></div>
                        <div x-show="exceedsCredit" class="rounded bg-danger-100 p-2 text-danger-700 dark:bg-danger-900 dark:text-danger-200">
                            هذا البيع يتجاوز الحد الائتماني للعميل.
                            <label x-show="config.can.override" class="mt-1 flex items-center gap-2">
                                <input type="checkbox" x-model="overrideCredit"> تجاوز الحد الائتماني (صلاحية مدير)
                            </label>
                        </div>
                    </div>
                </template>

                <div x-show="error" x-text="error" class="rounded-lg bg-danger-50 p-2 text-sm font-semibold text-danger-700 dark:bg-danger-950 dark:text-danger-200"></div>

                <div class="flex gap-2">
                    <button type="button" @click="checkout()" :disabled="busy || !cart.length"
                            class="flex-1 rounded-xl bg-success-600 py-3 text-lg font-bold text-white shadow hover:bg-success-700 disabled:opacity-50">
                        <span x-show="!busy">إتمام البيع (F9)</span>
                        <span x-show="busy">جارِ الحفظ…</span>
                    </button>
                    <button type="button" @click="clearCart()" class="rounded-xl border border-gray-300 px-4 text-sm dark:border-gray-700 dark:text-gray-200">مسح</button>
                </div>

                <template x-if="lastSale">
                    <div class="rounded-lg bg-success-50 p-2 text-sm text-success-800 dark:bg-success-950 dark:text-success-200">
                        تم حفظ الفاتورة <b x-text="lastSale.number"></b> — الإجمالي <b x-text="money(lastSale.total)"></b>
                        <span x-show="lastSale.change > 0"> — الباقي للعميل <b x-text="money(lastSale.change)"></b></span>
                        <span x-show="lastSale.balanceDue > 0"> — المتبقي آجل <b x-text="money(lastSale.balanceDue)"></b></span>
                        <a :href="lastSale.receiptUrl" target="receipt" class="ms-2 font-bold underline">طباعة الإيصال</a>
                    </div>
                </template>
            </div>
        </section>
    </div>
</x-filament-panels::page>
