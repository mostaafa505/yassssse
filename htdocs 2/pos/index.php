<?php
$pageTitle = 'نقطة البيع السريعة / POS';
require_once dirname(__DIR__) . '/includes/header.php';

requireAccess(['admin', 'cashier'], 'access_pos');

$categories = $pdo->query("SELECT * FROM categories ORDER BY sort ASC, name_ar ASC")->fetchAll();

// بيانات العلامة التجارية لازم تتحمّل هنا كمان (مش بس في sales/invoice.php)
// عشان إيصال البيع أوفلاين (اللي بيتطبع مباشرة من المتصفح من غير ما يعدي على
// السيرفر) يبقى نفس شكل وتنسيق فاتورة الطباعة الحرارية الحقيقية بالظبط —
// نفس الشعار ونفس بيانات التواصل، مش قالب مختلف مرتجل.
$posBrandInfo = getActiveBrandInfo();
$posFooterAr = getSetting($pdo, 'invoice_footer_ar', 'شكراً لتسوقكم معنا');
$posFooterEn = getSetting($pdo, 'invoice_footer_en', 'Thank you for your visit');

// نفس الصلاحيات اللي السيرفر بيفرضها فعليًا وقت حفظ البيع (save_sale.php) —
// لازم الواجهة تخفي/تعطّل التحكم بتاعها هنا كمان، وإلا كاشير غير مصرح له
// هيملى السلة ويدوس "إتمام البيع" ويتفاجئ برفض السيرفر بعد ما خلص، بدل ما
// الخيار يكون واضح من الأول إنه مش متاح له.
$canApplyDiscount = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('apply_discount');
$canApplyWholesale = in_array($_SESSION['user_role'] ?? '', ['admin', 'accountant']) || hasPermission('apply_wholesale_price');
?>

<div class="pos-container">
    <!-- Products & Search Panel (Left/Middle) -->
    <div class="pos-products-panel d-flex flex-column">
        <!-- شعار المتجر ظاهر دايمًا فوق شاشة البيع نفسها، مش بس في القائمة
             الجانبية — لأن القائمة الجانبية بتتقفل افتراضيًا على الموبايل/
             التابلت (نفس جهاز الكاشير الحقيقي غالبًا)، فكانت شاشتي البيع بتاعة
             دحانة وأدفنتشر بيبانوا متطابقين تمامًا من غير أي علامة تميّز
             المتجر، وده كان بيلغبط الكاشير لو شغال على أكتر من متجر. -->
        <div class="d-flex align-items-center gap-2 mb-3">
            <img src="<?= e($posBrandInfo['logo']) ?>" alt="<?= e($posBrandInfo['name_en']) ?>" style="height: 36px; object-fit: contain;">
            <span class="fw-bold text-dark"><?= e($posBrandInfo['name_ar']) ?></span>
        </div>
        <!-- Top Search Bar -->
        <div class="mb-4">
            <div class="input-group input-group-lg shadow-sm">
                <span class="input-group-text bg-white border-end-0"><i class="bi bi-upc-scan text-primary"></i></span>
                <input type="text" id="barcodeSearchInput" class="form-control border-start-0 border-end-0 py-3" placeholder="امسح الباركود بالماسح أو اكتب اسم المنتج أو الـ SKU..." autofocus>
                <button class="btn btn-light border border-start-0 border-end-0 px-3 text-primary" type="button" onclick="openCameraScanner()" title="مسح الباركود بكاميرا الجوال / التابلت"><i class="bi bi-camera-fill fs-5"></i></button>
                <button class="btn btn-white border border-start-0" type="button" onclick="loadProducts()"><i class="bi bi-arrow-clockwise"></i></button>
            </div>
            <!-- Categories Pills -->
            <div class="d-flex gap-2 overflow-auto pt-3 pb-1" id="categoryPills">
                <button class="btn btn-sm btn-dark rounded-pill px-4 active" onclick="filterCategory(0, this)">الكل</button>
                <?php foreach ($categories as $c): ?>
                    <button class="btn btn-sm btn-outline-secondary rounded-pill px-4 text-nowrap" onclick="filterCategory(<?= $c['id'] ?>, this)">
                        <?= e($c['name_ar']) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Products Grid -->
        <div class="pos-products-grid" id="productsContainer">
            <!-- Products rendered via JS -->
        </div>
    </div>

    <!-- Cart & Payment Panel (Right) -->
    <div class="pos-cart-panel shadow-lg">
        <div class="panel-header" style="background: var(--surface-app);">
            <h6 class="panel-title d-flex align-items-center">
                <i class="bi bi-cart3 me-2 text-primary fs-5"></i> سلة المشتريات
                <span class="badge bg-primary ms-auto num" id="cartCountBadge">0</span>
            </h6>
            <button class="btn btn-sm btn-light text-danger ms-2 border-0" onclick="clearCart()" title="تفريغ السلة"><i class="bi bi-trash"></i></button>
        </div>

        <!-- Cart Items List -->
        <ul id="cartItemsList" class="cart-items">
            <li class="text-center py-5 text-subtle">
                <i class="bi bi-cart-x fs-1 d-block mb-3 text-tertiary"></i>
                السلة فارغة، اختر منتجات لإضافتها للبيع
            </li>
        </ul>

        <!-- Payment Summary & Action -->
        <div class="cart-summary">
            <div class="summary-row">
                <span>المجموع الفرعي (Subtotal)</span>
                <span class="num fw-bold text-dark" id="subtotalDisplay">AED 0.00</span>
            </div>

            <div class="summary-row align-items-center mb-0">
                <span>الخصم (Discount)</span>
                <div class="input-group input-group-sm" style="width: 120px;">
                    <input type="number" step="0.5" min="0" id="discountInput" class="form-control text-end num" value="0" onchange="updateCartTotals()" <?= $canApplyDiscount ? '' : 'disabled title="غير مصرح لك بتطبيق خصم"' ?>>
                    <span class="input-group-text num">AED</span>
                </div>
            </div>

            <div class="summary-total">
                <span>الإجمالي النهائي</span>
                <span class="text-primary num" id="totalDisplay">AED 0.00</span>
            </div>

            <!-- Payment Method Select -->
            <div class="mt-4 mb-3">
                <span class="form-label">طريقة الدفع</span>
                <div class="btn-group w-100" role="group">
                    <input type="radio" class="btn-check" name="payMethod" id="payCash" value="cash" checked onchange="togglePayInputs()">
                    <label class="btn btn-outline-secondary" for="payCash"><i class="bi bi-cash me-1"></i> كاش</label>

                    <input type="radio" class="btn-check" name="payMethod" id="payCard" value="card" onchange="togglePayInputs()">
                    <label class="btn btn-outline-secondary" for="payCard"><i class="bi bi-credit-card me-1"></i> بطاقة</label>

                    <input type="radio" class="btn-check" name="payMethod" id="payTransfer" value="transfer" onchange="togglePayInputs()">
                    <label class="btn btn-outline-secondary" for="payTransfer"><i class="bi bi-bank me-1"></i> تحويل</label>
                </div>
            </div>

            <!-- Quick Cash Calculator -->
            <div id="cashCalcBox" class="mb-4">
                <div class="row g-2">
                    <div class="col-6">
                        <input type="number" step="1" id="cashGivenInput" class="form-control num" placeholder="المبلغ المدفوع" oninput="calculateChange()">
                    </div>
                    <div class="col-6">
                        <div class="form-control bg-success-subtle text-success fw-bold text-center border-0 num" id="changeDisplay">
                            الباقي: 0.00
                        </div>
                    </div>
                </div>
            </div>

            <button class="btn btn-primary btn-lg w-100 fw-bold py-3" id="completeSaleBtn" onclick="submitSale()">
                <i class="bi bi-printer-fill fs-5 me-2"></i> إتمام البيع والطباعة
            </button>
        </div>
    </div>
</div>

<!-- Variant Selection Modal -->
<div class="modal fade" id="variantModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold" id="variantModalTitle">اختر اللون والمقاس</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-3" id="variantModalBody">
                <!-- Injected via JS -->
            </div>
        </div>
    </div>
</div>

<!-- Receipt Fallback Modal — بتظهر لو المتصفح منع نافذة الفاتورة المنفصلة
     (popup blocker)، عشان الكاشير يشوف ويطبع الفاتورة برضو من غير ما تضيع -->
<div class="modal fade" id="receiptFallbackModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 340px;">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light no-print">
                <h6 class="modal-title fw-bold">الفاتورة</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-0" id="receiptFallbackContent"></div>
            <div class="modal-footer bg-light no-print">
                <button type="button" class="btn btn-primary w-100 fw-bold" onclick="window.print()">
                    <i class="bi bi-printer-fill me-1"></i> طباعة الفاتورة
                </button>
            </div>
        </div>
    </div>
</div>
<style>
@media print {
    body * { visibility: hidden !important; }
    #receiptFallbackContent, #receiptFallbackContent * { visibility: visible !important; }
    #receiptFallbackContent { position: absolute !important; top: 0; left: 0; width: 100%; }
    /* شريط "فاتورة مؤقتة" لازم يفضل مخفي وقت الطباعة الفعلية حتى لو اتعرضت
       الفاتورة من خلال المودال الاحتياطي ده (لما المتصفح يحظر الـ popup)،
       عشان العميل مايشوفش الجملة دي على الورقة المطبوعة له */
    #receiptFallbackContent .no-print { display: none !important; visibility: hidden !important; }
}
</style>

<!-- Camera Barcode Scanner Modal -->
<div class="modal fade" id="cameraScannerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-light">
                <h6 class="modal-title fw-bold"><i class="bi bi-camera text-primary me-2"></i> مسح الباركود عبر الكاميرا</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" onclick="stopCameraScanner()"></button>
            </div>
            <div class="modal-body p-3 text-center">
                <div id="cameraReader" style="width: 100%; min-height: 250px; background: #000; border-radius: 8px; overflow: hidden;"></div>
                <div class="small text-muted mt-2">وجّه الكاميرا نحو الباركود لقراءته وإضافته للسلة مباشرة</div>
            </div>
            <div class="modal-footer bg-light p-2">
                <button type="button" class="btn btn-secondary w-100 fw-bold" data-bs-dismiss="modal" onclick="stopCameraScanner()">إغلاق الكاميرا</button>
            </div>
        </div>
    </div>
</div>

<!-- HTML5 QR / Barcode Scanner CDN -->
<script src="https://unpkg.com/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>

<script>
const CAN_APPLY_WHOLESALE = <?= $canApplyWholesale ? 'true' : 'false' ?>;
let html5QrCode = null;

function openCameraScanner() {
    const modal = new bootstrap.Modal(document.getElementById('cameraScannerModal'));
    modal.show();

    setTimeout(() => {
        if (!html5QrCode) {
            html5QrCode = new Html5Qrcode("cameraReader");
        }
        html5QrCode.start(
            { facingMode: "environment" },
            { 
                fps: 15, 
                qrbox: { width: 280, height: 160 } 
            },
            (decodedText) => {
                stopCameraScanner();
                const bsModal = bootstrap.Modal.getInstance(document.getElementById('cameraScannerModal'));
                if (bsModal) bsModal.hide();
                handleDirectBarcode(decodedText);
            },
            (errorMessage) => {}
        ).catch(err => {
            console.error("Camera error:", err);
            alert("تعذر تشغيل الكاميرا. يرجى التأكد من إعطاء المتصفح إذن الوصول للكاميرا.");
        });
    }, 400);
}

function stopCameraScanner() {
    if (html5QrCode) {
        html5QrCode.stop().then(() => {
            html5QrCode.clear();
        }).catch(err => console.warn(err));
    }
}

// بيانات العلامة التجارية عشان إيصال الطباعة أوفلاين يبقى مطابق بالظبط
// لشكل فاتورة الطباعة الحرارية الحقيقية (sales/invoice.php) — نفس الشعار
// ونفس نص الشكر ونفس بيانات الموقع والإنستجرام، مش قالب تاني مرتجل.
const posBrand = {
    logo: <?= json_encode($posBrandInfo['logo']) ?>,
    name_ar: <?= json_encode($posBrandInfo['name_ar'], JSON_UNESCAPED_UNICODE) ?>,
    name_en: <?= json_encode($posBrandInfo['name_en']) ?>,
    website: <?= json_encode($posBrandInfo['website'] ?? '') ?>,
    instagram: <?= json_encode($posBrandInfo['instagram'] ?? '') ?>,
    footer_ar: <?= json_encode($posFooterAr, JSON_UNESCAPED_UNICODE) ?>,
    footer_en: <?= json_encode($posFooterEn) ?>
};

/**
 * يبني نفس تصميم فاتورة الطباعة الحرارية بالظبط (80مم) اللي في sales/invoice.php،
 * عشان الإيصال المطبوع أوفلاين ميبقاش شكل مختلف عن الفاتورة الرسمية. لو
 * isPending=true بيضيف شريط تنبيه "فاتورة مؤقتة" أعلى الإيصال.
 */
function buildThermalReceiptHtml(items, total, opts) {
    const lang = 'ar';
    const itemsHtml = items.map(i => `
        <div class="item-row">
            <span class="item-title">
                ${i.name_ar}
                ${(i.color || i.size) ? `<span style="font-weight: normal; font-size: 11px;">[${i.color || ''} ${i.size || ''}]</span>` : ''}
            </span>
            <div class="item-details">
                <span dir="ltr">${i.qty} × AED ${i.unitPrice.toFixed(2)}</span>
                <strong dir="ltr">AED ${(i.qty * i.unitPrice).toFixed(2)}</strong>
            </div>
        </div>
    `).join('');

    // شريط "فاتورة مؤقتة" ده للكاشير بس عشان يعرف إنها لسه هتتزامن — مش المفروض
    // يوصل للعميل خالص على الورقة المطبوعة، فهو no-print (يظهر على الشاشة لو
    // الكاشير فتح نفس النافذة تاني بعدين، بس بيتشال تلقائيًا وقت الطباعة الفعلية)
    const pendingBanner = opts.isPending ? `
        <div class="no-print" style="background:#fef3c7; color:#92400e; border: 1px dashed #d97706; border-radius:4px; padding:6px; margin-bottom:10px; font-size:11px; font-weight:700;">
            فاتورة مؤقتة — ستُرفع للسيرفر تلقائياً عند عودة الاتصال
        </div>
    ` : '';

    return `
        <html dir="rtl"><head><meta charset="UTF-8">
        <title>${opts.invoiceNumber}</title>
        <link href="https://fonts.googleapis.com/css2?family=Cairo:wght@400;600;700;800&display=swap" rel="stylesheet">
        <style>
            * { box-sizing: border-box; font-family: 'Cairo', sans-serif; }
            @page { size: 80mm auto; margin: 0; }
            body { background: #fff; color: #000; margin: 0; padding: 0; width: 100%; }
            .receipt-container { width: 76mm; max-width: 76mm; margin: 0 auto; background: #fff; padding: 5mm 3mm; font-size: 12px; line-height: 1.4; overflow: hidden; }
            .text-center { text-align: center; }
            .fw-bold { font-weight: 700; }
            .dashed-line { border-top: 1px dashed #000; margin: 8px 0; }
            .solid-line { border-top: 2px solid #000; margin: 10px 0; }
            .flex-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px; }
            .item-row { page-break-inside: avoid; break-inside: avoid; margin-bottom: 6px; padding-bottom: 6px; border-bottom: 1px dotted #ccc; }
            .item-row:last-child { border-bottom: none; margin-bottom: 0; padding-bottom: 0; }
            .item-title { font-weight: 700; margin-bottom: 2px; display: block; }
            .item-details { display: flex; justify-content: space-between; align-items: center; font-size: 11px; }
            .total-row { font-size: 16px; font-weight: 800; margin: 8px 0; }
            @media print { .no-print { display: none !important; } }
        </style>
        </head><body>
        <div class="receipt-container">
            ${pendingBanner}
            <div class="text-center" style="margin-bottom: 12px;">
                <img src="${posBrand.logo}" alt="${posBrand.name_en}" style="height: 95px; max-width: 210px; object-fit: contain;">
            </div>
            <div class="dashed-line"></div>
            <div class="flex-row"><span>رقم الفاتورة</span><strong dir="ltr">#${opts.invoiceNumber}</strong></div>
            <div class="flex-row"><span>التاريخ</span><span dir="ltr">${opts.dateStr}</span></div>
            <div class="flex-row"><span>رقم الكاشير</span><strong dir="ltr">#${opts.cashierNumber}</strong></div>
            <div class="flex-row"><span>طريقة الدفع</span><strong>${opts.paymentMethod}</strong></div>
            <div class="dashed-line"></div>
            <div style="margin-bottom: 10px;">
                <div class="flex-row fw-bold" style="border-bottom: 1px solid #000; padding-bottom: 4px; margin-bottom: 6px;">
                    <span>الصنف</span><span>المجموع</span>
                </div>
                ${itemsHtml}
            </div>
            <div class="solid-line"></div>
            <div class="flex-row total-row"><span>الإجمالي النهائي</span><span dir="ltr">AED ${total.toFixed(2)}</span></div>
            <div class="dashed-line" style="margin-top: 10px;"></div>
            <div class="text-center" style="margin-top: 15px; margin-bottom: 5px;">
                <div class="fw-bold" style="font-size: 13px; margin-bottom: 10px;">${posBrand.footer_ar}</div>
                <div style="display: flex; flex-direction: row; justify-content: space-between; align-items: center; padding: 0 5px;">
                    <div style="display: flex; align-items: center; gap: 4px; direction: ltr; font-weight: 700; font-size: 11px; color: #000;">${posBrand.website}</div>
                    <div style="display: flex; align-items: center; gap: 4px; direction: ltr; font-weight: 700; font-size: 11px; color: #000;">${posBrand.instagram}</div>
                </div>
            </div>
        </div>
        <script>window.onload = function() { window.print(); setTimeout(() => window.close(), 1500); };<\/script>
        </body></html>
    `;
}

let cart = [];
let allProducts = [];
let currentCategory = 0;

document.addEventListener('DOMContentLoaded', function() {
    loadProducts();

    const searchInput = document.getElementById('barcodeSearchInput');
    if (searchInput) searchInput.focus();
    searchInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            const term = this.value.trim();
            if (term !== '') {
                // If single barcode match, add directly
                handleDirectBarcode(term);
            }
        }
    });

    let typingTimer;
    searchInput.addEventListener('input', function() {
        clearTimeout(typingTimer);
        typingTimer = setTimeout(() => {
            loadProducts(this.value.trim());
        }, 300);
    });
});

function filterCategory(catId, btn) {
    currentCategory = catId;
    document.querySelectorAll('#categoryPills button').forEach(b => {
        b.classList.remove('btn-dark', 'active');
        b.classList.add('btn-outline-secondary');
    });
    btn.classList.remove('btn-outline-secondary');
    btn.classList.add('btn-dark', 'active');
    loadProducts(document.getElementById('barcodeSearchInput').value.trim());
}

function loadProducts(search = '') {
    const container = document.getElementById('productsContainer');
    container.innerHTML = '<div class="col-12 text-center py-5 text-muted"><span class="spinner-border spinner-border-sm me-2"></span>جاري تحميل المنتجات...</div>';

    const catParam = currentCategory > 0 ? `&cat=${currentCategory}` : '';

    if (!navigator.onLine) {
        // *** وضع أوفلاين: حمّل من IndexedDB ***
        getProductsFromCache().then(cached => {
            if (cached && cached.length > 0) {
                allProducts = cached;
                if (search) {
                    const s = search.toLowerCase();
                    allProducts = cached.filter(p =>
                        p.name_ar.includes(search) || p.name_en.toLowerCase().includes(s) ||
                        (p.sku && p.sku.toLowerCase().includes(s)) ||
                        (p.barcode && p.barcode.includes(search))
                    );
                }
                renderProductsGrid();
            } else {
                container.innerHTML = '<div class="col-12 text-center py-5 text-warning"><i class="bi bi-wifi-off fs-1 d-block mb-3"></i>لا يوجد كاش محلي — افتح الشاشة مرة وأنت متصل أولاً لتخزين المنتجات</div>';
            }
        });
        return;
    }

    fetch(`<?= BASE_URL ?>/pos/search_product.php?q=${encodeURIComponent(search)}${catParam}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                allProducts = data.products;
                renderProductsGrid();
                // *** خزّن في IndexedDB لو الطلب بدون فلتر ***
                if (!search && currentCategory === 0) {
                    saveProductsToCache(data.products).catch(() => {});
                }
            }
        })
        .catch(() => {
            // فشل الاتصال: جرب الكاش
            getProductsFromCache().then(cached => {
                if (cached && cached.length > 0) {
                    allProducts = cached;
                    renderProductsGrid();
                } else {
                    container.innerHTML = '<div class="col-12 text-center py-5 text-muted"><i class="bi bi-wifi-off fs-1 d-block mb-3"></i>تعذر الاتصال بالسيرفر ولا يوجد كاش محلي</div>';
                }
            });
        });
}

function renderProductsGrid() {
    const container = document.getElementById('productsContainer');
    container.innerHTML = '';

    let filtered = allProducts;
    if (currentCategory > 0) {
        filtered = allProducts.filter(p => p.category_id == currentCategory);
    }

    if (filtered.length === 0) {
        container.innerHTML = '<div class="col-12 text-center py-5 text-muted">لا توجد منتجات مطابقة.</div>';
        return;
    }

    filtered.forEach(p => {
        const stockBadge = p.has_variants 
            ? `<span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);"><i class="bi bi-diagram-3 me-1"></i>${p.variants.length}</span>`
            : `<span class="badge ${p.stock_qty <= 5 ? 'status-inactive' : 'status-active'} num">${p.stock_qty}</span>`;

        const priceDisplay = p.offer_price 
            ? `<div class="pos-product-price text-danger num">AED ${p.offer_price.toFixed(2)}</div> <del class="text-subtle small num">AED ${p.retail_price.toFixed(2)}</del>`
            : `<div class="pos-product-price num">AED ${p.retail_price.toFixed(2)}</div>`;

        const imgHtml = p.image 
            ? `<img src="${p.image}" class="pos-product-img" alt="${p.name_ar}">`
            : `<div class="pos-product-img d-flex align-items-center justify-content-center text-tertiary"><i class="bi bi-image fs-1"></i></div>`;

        const card = document.createElement('div');
        card.className = 'pos-product-card';
        card.onclick = function() { onProductClick(p.id); };
        card.innerHTML = `
            ${imgHtml}
            <div class="pos-product-info">
                <div>
                    <div class="pos-product-title" title="${p.name_ar}">${p.name_ar}</div>
                    <div class="text-caption text-truncate">${p.name_en || ''}</div>
                </div>
                <div class="d-flex justify-content-between align-items-end mt-2">
                    <div>${priceDisplay}</div>
                    <div>${stockBadge}</div>
                </div>
            </div>
        `;
        container.appendChild(card);
    });
}

function playBeep(success = true) {
    try {
        const audioCtx = new (window.AudioContext || window.webkitAudioContext)();
        const osc = audioCtx.createOscillator();
        const gain = audioCtx.createGain();
        osc.connect(gain);
        gain.connect(audioCtx.destination);
        osc.type = success ? 'sine' : 'sawtooth';
        osc.frequency.value = success ? 880 : 330; // High beep on success, low on error
        gain.gain.setValueAtTime(0.15, audioCtx.currentTime);
        gain.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + (success ? 0.12 : 0.2));
        osc.start();
        osc.stop(audioCtx.currentTime + (success ? 0.12 : 0.2));
    } catch(e) {}
}

function handleDirectBarcode(term) {
    if (!term) return;
    const searchInput = document.getElementById('barcodeSearchInput');

    fetch(`<?= BASE_URL ?>/pos/search_product.php?barcode=${encodeURIComponent(term)}`)
        .then(res => res.json())
        .then(data => {
            if (data.success) {
                playBeep(true);
                if (data.match === 'variant') {
                    addToCart(data.product, data.variant);
                } else if (data.match === 'product') {
                    if (data.product.has_variants && data.product.variants && data.product.variants.length > 0) {
                        openVariantModal(data.product);
                    } else {
                        addToCart(data.product, null);
                    }
                }
                searchInput.value = '';
                searchInput.focus();
            } else {
                playBeep(false);
                // Fallback: search client list
                for (let p of allProducts) {
                    if (p.barcode === term || p.sku === term) {
                        onProductClick(p.id);
                        searchInput.value = '';
                        searchInput.focus();
                        return;
                    }
                }
                alert(data.error || ('عذراً، لم يتم العثور على أي منتج يحمل هذا الباركود: ' + term));
                searchInput.select();
            }
        })
        .catch(err => {
            console.error('Barcode lookup error:', err);
        });
}

function onProductClick(productId) {
    const p = allProducts.find(item => item.id === productId);
    if (!p) return;

    if (p.has_variants && p.variants.length > 0) {
        openVariantModal(p);
    } else {
        addToCart(p, null);
    }
}

function openVariantModal(p) {
    document.getElementById('variantModalTitle').innerText = `${p.name_ar} — اختر اللون والمقاس`;
    const body = document.getElementById('variantModalBody');
    body.innerHTML = '';

    const list = document.createElement('div');
    list.className = 'd-flex flex-column gap-2';

    p.variants.forEach(v => {
        const row = document.createElement('div');
        row.className = 'd-flex justify-content-between align-items-center p-3 border rounded-3 hover-bg';
        row.style.cursor = 'pointer';
        row.onclick = () => {
            addToCart(p, v);
            bootstrap.Modal.getInstance(document.getElementById('variantModal')).hide();
        };

        row.innerHTML = `
            <div>
                <span class="fw-bold text-dark">${v.color_ar || '—'}</span>
                ${v.size ? `<span class="badge ms-2" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">${v.size}</span>` : ''}
                <div class="text-caption mt-1">SKU: ${v.sku || '—'} | باركود: ${v.barcode || '—'}</div>
            </div>
            <div class="text-end">
                <div class="fw-bold text-primary num">AED ${v.retail_price.toFixed(2)}</div>
                <small class="${v.stock_qty <= 0 ? 'text-danger' : 'text-success'} num fw-medium">${v.stock_qty} متوفر</small>
            </div>
        `;
        list.appendChild(row);
    });

    body.appendChild(list);
    new bootstrap.Modal(document.getElementById('variantModal')).show();
}

function addToCart(product, variant = null) {
    const cartItemId = variant ? `${product.id}_${variant.id}` : `${product.id}_0`;
    const existing = cart.find(item => item.cartItemId === cartItemId);

    let priceType = 'retail';
    let unitPrice = variant ? variant.retail_price : product.retail_price;

    if (existing) {
        existing.qty += 1;
    } else {
        cart.push({
            cartItemId,
            productId: product.id,
            variantId: variant ? variant.id : null,
            name_ar: product.name_ar,
            name_en: product.name_en,
            color: variant ? variant.color_ar : null,
            size: variant ? variant.size : null,
            qty: 1,
            priceType: 'retail',
            prices: {
                retail: variant ? variant.retail_price : product.retail_price,
                offer: (variant && variant.offer_price) ? variant.offer_price : product.offer_price,
                wholesale: (variant && variant.wholesale_price) ? variant.wholesale_price : product.wholesale_price
            },
            unitPrice: unitPrice
        });
    }

    renderCart();
}

function changeItemQty(cartItemId, delta) {
    const item = cart.find(i => i.cartItemId === cartItemId);
    if (!item) return;
    item.qty += delta;
    if (item.qty <= 0) {
        cart = cart.filter(i => i.cartItemId !== cartItemId);
    }
    renderCart();
}

function setItemPriceType(cartItemId, type) {
    const item = cart.find(i => i.cartItemId === cartItemId);
    if (!item) return;
    item.priceType = type;
    if (type === 'offer' && item.prices.offer) {
        item.unitPrice = item.prices.offer;
    } else if (type === 'wholesale' && item.prices.wholesale) {
        item.unitPrice = item.prices.wholesale;
    } else {
        item.unitPrice = item.prices.retail;
    }
    renderCart();
}

function removeFromCart(cartItemId) {
    cart = cart.filter(i => i.cartItemId !== cartItemId);
    renderCart();
}

function clearCart() {
    if (cart.length === 0 || confirm('هل أنت متأكد من مسح جميع المنتجات من السلة؟')) {
        cart = [];
        renderCart();
    }
}

function renderCart() {
    const list = document.getElementById('cartItemsList');
    list.innerHTML = '';

    if (cart.length === 0) {
        list.innerHTML = `
            <li class="text-center py-5 text-subtle">
                <i class="bi bi-cart-x fs-1 d-block mb-3 text-tertiary"></i>
                السلة فارغة، اختر منتجات لإضافتها للبيع
            </li>
        `;
        document.getElementById('cartCountBadge').innerText = '0';
        updateCartTotals();
        return;
    }

    document.getElementById('cartCountBadge').innerText = cart.reduce((sum, i) => sum + i.qty, 0);

    cart.forEach(item => {
        const itemTotal = item.qty * item.unitPrice;
        const li = document.createElement('li');
        li.className = 'cart-item';

        li.innerHTML = `
            <div class="d-flex justify-content-between align-items-start mb-2">
                <div>
                    <strong class="text-dark d-block lh-sm mb-1">${item.name_ar}</strong>
                    ${(item.color || item.size) ? `<span class="badge" style="background: var(--surface-app); border: 1px solid var(--border-strong); color: var(--text-secondary);">${item.color || ''} ${item.size || ''}</span>` : ''}
                </div>
                <button class="btn btn-sm text-danger p-1" onclick="removeFromCart('${item.cartItemId}')"><i class="bi bi-x-lg"></i></button>
            </div>

            <div class="d-flex justify-content-between align-items-center mt-3">
                <!-- Qty controls -->
                <div class="input-group input-group-sm" style="width: 100px;">
                    <button class="btn btn-outline-secondary px-2" onclick="changeItemQty('${item.cartItemId}', -1)"><i class="bi bi-dash"></i></button>
                    <input type="text" class="form-control text-center p-0 num fw-bold" value="${item.qty}" readonly>
                    <button class="btn btn-outline-secondary px-2" onclick="changeItemQty('${item.cartItemId}', 1)"><i class="bi bi-plus"></i></button>
                </div>

                <!-- Price Type Select -->
                <select class="form-select form-select-sm" style="width: 90px;" onchange="setItemPriceType('${item.cartItemId}', this.value)">
                    <option value="retail" ${item.priceType === 'retail' ? 'selected' : ''}>تجزئة</option>
                    ${item.prices.offer ? `<option value="offer" ${item.priceType === 'offer' ? 'selected' : ''}>عرض</option>` : ''}
                    ${(item.prices.wholesale && CAN_APPLY_WHOLESALE) ? `<option value="wholesale" ${item.priceType === 'wholesale' ? 'selected' : ''}>جملة</option>` : ''}
                </select>

                <!-- Total -->
                <div class="fw-bold text-primary num fs-6">AED ${itemTotal.toFixed(2)}</div>
            </div>
        `;
        list.appendChild(li);
    });

    updateCartTotals();
}

function updateCartTotals() {
    const subtotal = cart.reduce((sum, i) => sum + (i.qty * i.unitPrice), 0);
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;
    const total = Math.max(0, subtotal - discount);

    document.getElementById('subtotalDisplay').innerText = `AED ${subtotal.toFixed(2)}`;
    document.getElementById('totalDisplay').innerText = `AED ${total.toFixed(2)}`;

    calculateChange();
}

function calculateChange() {
    const total = parseFloat(document.getElementById('totalDisplay').innerText.replace('AED ', '')) || 0;
    const given = parseFloat(document.getElementById('cashGivenInput').value) || 0;
    const change = Math.max(0, given - total);
    document.getElementById('changeDisplay').innerText = `الباقي: AED ${change.toFixed(2)}`;
}

function togglePayInputs() {
    const method = document.querySelector('input[name="payMethod"]:checked').value;
    const cashBox = document.getElementById('cashCalcBox');
    if (method === 'cash') {
        cashBox.style.display = 'block';
    } else {
        cashBox.style.display = 'none';
    }
}

/**
 * يورّي الفاتورة: يحاول ينفعها في النافذة المنفصلة اللي اتفتحت فورًا وقت
 * الضغط على الزرار (لو لسه مفتوحة ومش اتحظرت)، وإلا (لو المتصفح حظر
 * الـ popup) يعرضها جوه مودال في نفس الصفحة مع زرار طباعة يدوي — عشان
 * الكاشير يضمن يشوف ويطبع الفاتورة في كل الأحوال.
 */
function showReceiptHtml(receiptWindow, html) {
    if (receiptWindow && !receiptWindow.closed) {
        try {
            receiptWindow.document.open();
            receiptWindow.document.write(html);
            receiptWindow.document.close();
            return;
        } catch (e) { /* كمل على الـ fallback */ }
    }
    // الـ popup اتحظر أو اتقفل — ورّي الفاتورة جوه الصفحة نفسها
    // بنمسح الـ <script> والـ <style> اللي جوه الـ html (المتصفح مش بيحافظ عليهم
    // صح وقت الحقن جوه innerHTML أصلاً)، وبنمسح شريط "فاتورة مؤقتة" نهائيًا من
    // الـ DOM هنا (مش بس نخفيه بالـ CSS) عشان نضمن مايتطبعش للعميل بأي شكل
    const content = document.getElementById('receiptFallbackContent');
    let cleanHtml = html
        .replace(/<script[\s\S]*?<\/script>/gi, '')
        .replace(/<div class="no-print"[\s\S]*?<\/div>\s*/i, '');
    content.innerHTML = cleanHtml;
    new bootstrap.Modal(document.getElementById('receiptFallbackModal')).show();
}

function submitSale() {
    if (cart.length === 0) {
        alert('السلة فارغة!');
        return;
    }

    // لازم نفتح نافذة الفاتورة دلوقتي، فورًا، جوه استدعاء الزرار مباشرة (Synchronous)
    // — لو فتحناها بعدين جوه .then() بعد عملية غير متزامنة (حفظ IndexedDB أو
    // fetch)، متصفحات كتير (خصوصًا على الموبايل) بتعتبرها popup مش طلب المستخدم
    // نفسه وبتحظرها من غير أي رسالة تحذير، فالفاتورة "تختفي" بصمت. فتحها فورًا
    // هنا يضمن إنها تتقبل، وعندنا كمان fallback جوه الصفحة لو حصل حظر برضو.
    let receiptWindow = null;
    try {
        receiptWindow = window.open('', '_blank', 'width=400,height=600');
        if (receiptWindow) {
            receiptWindow.document.write('<html dir="rtl"><body style="font-family:sans-serif;text-align:center;padding:60px 20px;color:#888;">جارٍ تجهيز الفاتورة...</body></html>');
        }
    } catch (e) { receiptWindow = null; }

    const btn = document.getElementById('completeSaleBtn');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>جاري الحفظ والطباعة...';

    const method = document.querySelector('input[name="payMethod"]:checked').value;
    const discount = parseFloat(document.getElementById('discountInput').value) || 0;

    const payload = {
        payment_method: method,
        discount: discount,
        customer_name: document.getElementById('customerNameInput') ? document.getElementById('customerNameInput').value : '',
        customer_phone: '',
        notes: '',
        created_at: new Date().toISOString(),
        items: cart.map(i => ({
            product_id: i.productId,
            variant_id: i.variantId,
            qty: i.qty,
            price_type: i.priceType,
            unit_price: i.unitPrice,
            name_ar: i.name_ar,
            name_en: i.name_en,
            sku: i.sku || ''
        }))
    };

    function printOfflineReceipt() {
        const offlineInvoiceNum = 'OFFLINE-' + Date.now();
        const subtotal = cart.reduce((s, i) => s + i.qty * i.unitPrice, 0);
        const total = Math.max(0, subtotal - discount);
        const payMethodLabel = { cash: 'CASH', card: 'CARD', transfer: 'TRANSFER' }[method] || method.toUpperCase();
        const html = buildThermalReceiptHtml(cart, total, {
            invoiceNumber: offlineInvoiceNum,
            dateStr: new Date().toISOString().slice(0, 16).replace('T', ' '),
            cashierNumber: (JSON.parse(localStorage.getItem('pos_user') || '{}').id) || '—',
            paymentMethod: payMethodLabel,
            isPending: true
        });
        showReceiptHtml(receiptWindow, html);
    }

    // ─── وضع أوفلاين: احفظ محلياً ────────────────────────────────────────────
    if (!navigator.onLine) {
        addPendingSale(payload).then(localId => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-printer-fill me-2"></i> إتمام البيع وطباعة الفاتورة';

            printOfflineReceipt();

            cart = [];
            renderCart();
            updateOfflineBar();

            // طلب Background Sync
            if ('serviceWorker' in navigator && navigator.serviceWorker.controller) {
                navigator.serviceWorker.ready.then(reg => {
                    if ('sync' in reg) reg.sync.register('sync-pending-sales');
                });
            }
        }).catch(err => {
            btn.disabled = false;
            btn.innerHTML = '<i class="bi bi-printer-fill me-2"></i> إتمام البيع وطباعة الفاتورة';
            if (receiptWindow && !receiptWindow.closed) receiptWindow.close();
            alert('خطأ في حفظ الفاتورة محلياً: ' + err);
        });
        return;
    }

    // ─── وضع أونلاين: أرسل للسيرفر مباشرة ───────────────────────────────────
    fetch('<?= BASE_URL ?>/pos/save_sale.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify(payload)
    })
    .then(res => res.json())
    .then(data => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-printer-fill me-2"></i> إتمام البيع وطباعة الفاتورة';

        if (data.success) {
            const invoiceUrl = `<?= BASE_URL ?>/sales/invoice.php?id=${data.sale_id}&autoprint=1`;
            if (receiptWindow && !receiptWindow.closed) {
                // استخدم نفس النافذة اللي فتحناها فورًا بدل ما نفتح واحدة جديدة
                receiptWindow.location.href = invoiceUrl;
            } else {
                // الـ popup اتحظر من الأول — هات الفاتورة الحقيقية واعرضها جوه الصفحة
                fetch(invoiceUrl).then(r => r.text()).then(html => showReceiptHtml(null, html))
                    .catch(() => alert('تم حفظ الفاتورة بنجاح لكن تعذّر عرضها للطباعة. راجعها من شاشة الفواتير.'));
            }
            cart = [];
            renderCart();
            loadProducts();
        } else {
            if (receiptWindow && !receiptWindow.closed) receiptWindow.close();
            alert(data.error || 'حدث خطأ أثناء حفظ الفاتورة');
        }
    })
    .catch(() => {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-printer-fill me-2"></i> إتمام البيع وطباعة الفاتورة';
        // لو فشل الاتصال فجأة (كان أونلاين وقطع في نفس اللحظة)، احفظ أوفلاين
        // واطبع نفس إيصال الأوفلاين — الكاشير لازم ياخد ورقة للعميل في الحالتين
        addPendingSale(payload).then(() => {
            printOfflineReceipt();
            cart = [];
            renderCart();
            updateOfflineBar();
        });
    });
}

// ─── مزامنة يدوية ────────────────────────────────────────────────────────────
async function manualSync() {
    const pending = await getPendingSales();
    if (pending.length === 0) { alert('لا توجد فواتير معلقة.'); return; }

    const syncBtn = document.getElementById('syncBtn');
    syncBtn.disabled = true;
    syncBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> جارٍ الرفع...';

    try {
        const res  = await fetch('<?= BASE_URL ?>/pos/sync_offline.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ sales: pending })
        });
        const data = await res.json();

        if (data.success) {
            for (const id of data.synced_ids) await removePendingSale(id);
            for (const r of (data.failed_reasons || [])) await markPendingSaleError(r.local_id, r.reason);
            let msg = `✅ تمت مزامنة ${data.synced_ids.length} فاتورة بنجاح.`;
            if (data.failed > 0) {
                msg += `\n⚠️ ${data.failed} فاتورة فشلت مزامنتها ولن تختفي حتى تُحل:\n`;
                (data.failed_reasons || []).forEach(r => { msg += `• ${r.reason}\n`; });
            }
            alert(msg);
            if (typeof renderPendingSalesRows === 'function') renderPendingSalesRows();
        } else {
            alert('فشلت المزامنة. تأكد من الاتصال بالانترنت.');
        }
    } catch (e) {
        alert('لا يوجد اتصال بالانترنت حالياً.');
    } finally {
        syncBtn.disabled = false;
        syncBtn.innerHTML = '<i class="bi bi-arrow-repeat me-1"></i> رفع الآن';
        updateOfflineBar();
    }
}

// ─── مراقبة حالة الاتصال ─────────────────────────────────────────────────────
async function updateOfflineBar() {
    const bar      = document.getElementById('offlineBar');
    const barText  = document.getElementById('offlineBarText');
    const syncBtn  = document.getElementById('syncBtn');
    const count    = await countPendingSales();

    if (!navigator.onLine) {
        bar.style.display = 'block';
        bar.style.background = '#d97706';
        barText.textContent = count > 0
            ? `وضع بدون اتصال — ${count} فاتورة معلقة ستُرفع عند عودة الانترنت`
            : 'وضع بدون اتصال — المنتجات من الكاش المحلي';
        syncBtn.style.display = 'none';
    } else if (count > 0) {
        bar.style.display = 'block';
        bar.style.background = '#0369a1';
        barText.textContent = `عاد الاتصال — ${count} فاتورة معلقة بانتظار الرفع`;
        syncBtn.style.display = 'inline-block';
        // رفع تلقائي فوري
        manualSync();
    } else {
        bar.style.display = 'none';
    }
}

window.addEventListener('online',  () => { updateOfflineBar(); loadProducts(); });
window.addEventListener('offline', () => { updateOfflineBar(); loadProducts(); });

// تحقق من الفواتير المعلقة عند فتح الصفحة
document.addEventListener('DOMContentLoaded', () => updateOfflineBar());
</script>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>

<!-- Offline DB Helper -->
<script src="<?= BASE_URL ?>/assets/js/offline-db.js"></script>

<!-- شريط حالة الاتصال -->
<div id="offlineBar" style="display:none; position:fixed; top:0; left:0; right:0; z-index:9999; background:#d97706; color:#fff; text-align:center; padding:8px 16px; font-weight:700; font-size:0.9rem; font-family:'Cairo',sans-serif; direction:rtl;">
    <i class="bi bi-wifi-off me-2"></i>
    <span id="offlineBarText">وضع بدون اتصال — المنتجات من الكاش المحلي</span>
    &nbsp;&nbsp;
    <button onclick="manualSync()" id="syncBtn" style="display:none; background:#fff; color:#d97706; border:none; border-radius:6px; padding:2px 12px; font-weight:700; cursor:pointer; font-family:'Cairo',sans-serif;">
        <i class="bi bi-arrow-repeat me-1"></i> رفع الآن
    </button>
</div>

<?php // هذا يُغلق الـ footer الموجود فعلاً بالأسفل، لا تضيفه مرتين ?>
