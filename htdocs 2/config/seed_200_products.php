<?php
/**
 * Seed 200 realistic products for Dahana POS
 * Abaya / modest fashion store — UAE market
 * Run once at: http://localhost/store/config/seed_200_products.php
 */
require_once dirname(__DIR__) . '/config/config.php';

// Only allow admin or CLI
if (php_sapi_name() !== 'cli') {
    requireAuth(['admin']);
}

$inserted = 0;
$skipped  = 0;

// ── Fetch or create categories ──────────────────────────────────────────────
$catMap = [];
foreach ($pdo->query("SELECT id, name_ar FROM categories") as $row) {
    $catMap[$row['name_ar']] = $row['id'];
}

function ensureCat(PDO $pdo, array &$catMap, string $nameAr, string $nameEn): int {
    if (isset($catMap[$nameAr])) return $catMap[$nameAr];
    $pdo->prepare("INSERT INTO categories (name_ar, name_en, sort) VALUES (?, ?, 10)")->execute([$nameAr, $nameEn]);
    $id = (int)$pdo->lastInsertId();
    $catMap[$nameAr] = $id;
    return $id;
}

// ── Helper ───────────────────────────────────────────────────────────────────
function sku(string $prefix, int $n): string {
    return strtoupper($prefix) . '-' . str_pad($n, 4, '0', STR_PAD_LEFT);
}

// ── Product Definitions (200 products) ──────────────────────────────────────
// Each: [name_ar, name_en, cat_ar, cat_en, cost, retail, wholesale, stock, min_stock]
$products = [

    // ═══ عبايات (60) ════════════════════════════════════════════════════════
    ['عباية كلاسيكية سوداء مطرزة', 'Embroidered Black Abaya', 'عبايات', 'Abayas', 130, 280, 240, 25, 5],
    ['عباية شيفون فراشة', 'Butterfly Chiffon Abaya', 'عبايات', 'Abayas', 100, 220, 185, 18, 4],
    ['عباية مزينة بالدانتيل', 'Lace Trimmed Abaya', 'عبايات', 'Abayas', 150, 320, 275, 15, 3],
    ['عباية كريب بيضاء', 'White Crepe Abaya', 'عبايات', 'Abayas', 90, 195, 165, 20, 4],
    ['عباية بألوان باستيل', 'Pastel Color Abaya', 'عبايات', 'Abayas', 110, 240, 200, 22, 5],
    ['عباية رمادية بأكمام واسعة', 'Grey Wide Sleeve Abaya', 'عبايات', 'Abayas', 95, 210, 175, 18, 4],
    ['عباية فصلية بزهور', 'Floral Season Abaya', 'عبايات', 'Abayas', 120, 260, 220, 12, 3],
    ['عباية قفطان مصرية', 'Egyptian Kaftan Abaya', 'عبايات', 'Abayas', 140, 300, 255, 14, 3],
    ['عباية إماراتية تقليدية', 'Traditional Emirati Abaya', 'عبايات', 'Abayas', 180, 390, 335, 10, 2],
    ['عباية دبلة رصاصية', 'Slate Double Abaya', 'عبايات', 'Abayas', 160, 345, 295, 16, 4],
    ['عباية حرير طبيعي', 'Natural Silk Abaya', 'عبايات', 'Abayas', 280, 590, 510, 8, 2],
    ['عباية بالفراء الصناعي', 'Faux Fur Abaya', 'عبايات', 'Abayas', 200, 430, 370, 10, 2],
    ['عباية تطريز يدوي', 'Hand Embroidery Abaya', 'عبايات', 'Abayas', 220, 470, 400, 12, 3],
    ['عباية عاجي بأزرار ذهبية', 'Ivory Gold Button Abaya', 'عبايات', 'Abayas', 175, 375, 320, 15, 3],
    ['عباية ترتر فضي', 'Silver Glitter Abaya', 'عبايات', 'Abayas', 190, 410, 350, 12, 3],
    ['عباية ترتر ذهبي', 'Gold Glitter Abaya', 'عبايات', 'Abayas', 190, 410, 350, 12, 3],
    ['عباية جيرسيه رياضية', 'Jersey Sport Abaya', 'عبايات', 'Abayas', 70, 149, 125, 30, 6],
    ['عباية مودرن بحزام جلدي', 'Modern Leather Belt Abaya', 'عبايات', 'Abayas', 155, 335, 285, 14, 3],
    ['عباية ساتان لامع', 'Shiny Satin Abaya', 'عبايات', 'Abayas', 145, 315, 270, 16, 4],
    ['عباية بتطريز الخليج', 'Gulf Embroidery Abaya', 'عبايات', 'Abayas', 210, 450, 385, 11, 2],
    ['عباية حرير مورد', 'Floral Silk Abaya', 'عبايات', 'Abayas', 260, 560, 480, 9, 2],
    ['عباية بأكمام الفراشة', 'Butterfly Sleeve Abaya', 'عبايات', 'Abayas', 115, 250, 210, 17, 4],
    ['عباية جبردين ملونة', 'Colored Gabardine Abaya', 'عبايات', 'Abayas', 105, 225, 190, 20, 4],
    ['عباية قطيفة شتوية', 'Winter Velvet Abaya', 'عبايات', 'Abayas', 200, 430, 370, 13, 3],
    ['عباية مودرن رصاصية بجيوب', 'Grey Pocket Modern Abaya', 'عبايات', 'Abayas', 130, 280, 240, 18, 4],
    ['عباية أزرق سماوي', 'Sky Blue Abaya', 'عبايات', 'Abayas', 110, 240, 200, 20, 4],
    ['عباية أخضر زيتي', 'Olive Green Abaya', 'عبايات', 'Abayas', 110, 240, 200, 20, 4],
    ['عباية وردي فاتح', 'Light Pink Abaya', 'عبايات', 'Abayas', 110, 240, 200, 20, 4],
    ['عباية بنفسجي ملكي', 'Royal Purple Abaya', 'عبايات', 'Abayas', 120, 260, 220, 17, 4],
    ['عباية ذهبي فاخر', 'Luxury Gold Abaya', 'عبايات', 'Abayas', 300, 645, 550, 7, 2],
    ['عباية فيروزي بطرحة', 'Turquoise Abaya with Shawl', 'عبايات', 'Abayas', 165, 355, 300, 12, 3],
    ['عباية بتصميم بوهيمي', 'Bohemian Design Abaya', 'عبايات', 'Abayas', 125, 270, 230, 15, 3],
    ['عباية حرير مخمل مزيج', 'Silk Velvet Mix Abaya', 'عبايات', 'Abayas', 240, 515, 440, 9, 2],
    ['عباية صيفية خفيفة', 'Light Summer Abaya', 'عبايات', 'Abayas', 80, 170, 145, 25, 5],
    ['عباية مطبوعة بالحيوانات', 'Animal Print Abaya', 'عبايات', 'Abayas', 115, 250, 210, 16, 4],
    ['عباية شتوية بطانة دافئة', 'Warm Lined Winter Abaya', 'عبايات', 'Abayas', 190, 410, 350, 14, 3],
    ['عباية بنقش هندسي', 'Geometric Pattern Abaya', 'عبايات', 'Abayas', 130, 280, 240, 18, 4],
    ['عباية موندي الكلاسيكية', 'Classic Mondi Abaya', 'عبايات', 'Abayas', 145, 315, 265, 16, 4],
    ['عباية مع حجاب متناسق', 'Abaya with Matching Hijab', 'عبايات', 'Abayas', 170, 365, 310, 13, 3],
    ['عباية دبلة رصاصية فاخرة', 'Luxury Double Grey Abaya', 'عبايات', 'Abayas', 250, 535, 460, 8, 2],
    ['عباية حرير بأزرار لؤلؤ', 'Silk Pearl Button Abaya', 'عبايات', 'Abayas', 290, 625, 535, 7, 2],
    ['عباية بفصوص كريستال', 'Crystal Studded Abaya', 'عبايات', 'Abayas', 220, 475, 405, 10, 2],
    ['عباية جبون فضي', 'Silver Ribbon Abaya', 'عبايات', 'Abayas', 160, 345, 295, 15, 3],
    ['عباية بثيم نجوم', 'Star Theme Abaya', 'عبايات', 'Abayas', 135, 290, 248, 16, 4],
    ['عباية أنيقة بحافة مطرزة', 'Elegant Embroidered Edge Abaya', 'عبايات', 'Abayas', 175, 380, 325, 13, 3],
    ['عباية مفتوحة أمامية', 'Front Open Abaya', 'عبايات', 'Abayas', 100, 215, 182, 22, 5],
    ['عباية رسمية للمناسبات', 'Formal Occasion Abaya', 'عبايات', 'Abayas', 260, 560, 480, 9, 2],
    ['عباية يومية مريحة', 'Comfortable Daily Abaya', 'عبايات', 'Abayas', 85, 182, 155, 28, 6],
    ['عباية مطبوع ورود كبيرة', 'Large Rose Print Abaya', 'عبايات', 'Abayas', 120, 260, 220, 18, 4],
    ['عباية صوف هندية', 'Indian Wool Abaya', 'عبايات', 'Abayas', 155, 335, 285, 14, 3],
    ['عباية للحفلات ترتر متعدد', 'Multi Glitter Party Abaya', 'عبايات', 'Abayas', 230, 495, 425, 10, 2],
    ['عباية جيرسيه عملية ببطانة', 'Practical Lined Jersey Abaya', 'عبايات', 'Abayas', 95, 205, 174, 23, 5],
    ['عباية كريب دبلة بحزام', 'Double Crepe Belt Abaya', 'عبايات', 'Abayas', 140, 300, 256, 16, 4],
    ['عباية كاجوال بجيب', 'Casual Pocket Abaya', 'عبايات', 'Abayas', 88, 190, 162, 25, 5],
    ['عباية للسفر مقاومة للتجعد', 'Wrinkle Resistant Travel Abaya', 'عبايات', 'Abayas', 130, 280, 240, 18, 4],
    ['عباية بتصميم أندلسي', 'Andalusian Design Abaya', 'عبايات', 'Abayas', 200, 430, 368, 11, 2],
    ['عباية باللون الكحلي الداكن', 'Dark Navy Abaya', 'عبايات', 'Abayas', 115, 248, 211, 20, 4],
    ['عباية بأكمام بالون', 'Balloon Sleeve Abaya', 'عبايات', 'Abayas', 125, 270, 230, 17, 4],
    ['عباية باللون الرمادي الفاتح', 'Light Grey Abaya', 'عبايات', 'Abayas', 100, 215, 183, 20, 4],
    ['عباية مودرن بسحاب', 'Modern Zipper Abaya', 'عبايات', 'Abayas', 135, 290, 248, 18, 4],
    ['عباية بتطريز مورد ملون', 'Colorful Flower Embroidery Abaya', 'عبايات', 'Abayas', 195, 420, 360, 11, 2],

    // ═══ أحجبة وخمار (30) ═══════════════════════════════════════════════════
    ['حجاب شيفون ناعم', 'Soft Chiffon Hijab', 'أحجبة وخمار', 'Hijabs', 15, 35, 28, 60, 10],
    ['حجاب شيفون بالطباعة الزهرية', 'Floral Print Chiffon Hijab', 'أحجبة وخمار', 'Hijabs', 18, 42, 34, 50, 10],
    ['خمار مودرن للصلاة', 'Modern Prayer Khimar', 'أحجبة وخمار', 'Hijabs', 30, 65, 55, 40, 8],
    ['حجاب كريب مربع', 'Square Crepe Hijab', 'أحجبة وخمار', 'Hijabs', 20, 45, 37, 50, 10],
    ['حجاب قطني مريح', 'Comfortable Cotton Hijab', 'أحجبة وخمار', 'Hijabs', 12, 28, 23, 70, 12],
    ['شيلة خليجية شفافة', 'Sheer Gulf Sheila', 'أحجبة وخمار', 'Hijabs', 25, 55, 46, 45, 8],
    ['حجاب جيرسيه لا يحتاج دبوس', 'Pin Free Jersey Hijab', 'أحجبة وخمار', 'Hijabs', 22, 49, 41, 55, 10],
    ['حجاب ساتان لامع', 'Shiny Satin Hijab', 'أحجبة وخمار', 'Hijabs', 20, 44, 37, 48, 9],
    ['حجاب مخمل شتوي', 'Velvet Winter Hijab', 'أحجبة وخمار', 'Hijabs', 28, 62, 52, 35, 7],
    ['طرحة تل مطرزة', 'Embroidered Tulle Scarf', 'أحجبة وخمار', 'Hijabs', 35, 78, 66, 30, 6],
    ['حجاب لين بربطة', 'Tie Soft Hijab', 'أحجبة وخمار', 'Hijabs', 18, 40, 33, 50, 10],
    ['بونيه داخلي قطني', 'Cotton Inner Bonnet', 'أحجبة وخمار', 'Hijabs', 8, 18, 15, 90, 15],
    ['حجاب لؤلؤ متلألئ', 'Pearl Shimmer Hijab', 'أحجبة وخمار', 'Hijabs', 40, 88, 75, 28, 6],
    ['حجاب كشكش أنيق', 'Elegant Ruffled Hijab', 'أحجبة وخمار', 'Hijabs', 32, 70, 59, 35, 7],
    ['حجاب أزرق نيلي فيسكوز', 'Nile Blue Viscose Hijab', 'أحجبة وخمار', 'Hijabs', 16, 36, 30, 55, 10],
    ['حجاب نقي أبيض', 'Pure White Hijab', 'أحجبة وخمار', 'Hijabs', 14, 32, 26, 60, 12],
    ['حجاب رمادي فاتح', 'Light Grey Hijab', 'أحجبة وخمار', 'Hijabs', 14, 32, 26, 60, 12],
    ['حجاب كاكي عسكري', 'Military Khaki Hijab', 'أحجبة وخمار', 'Hijabs', 16, 36, 30, 52, 10],
    ['حجاب لافندر فيسكوز', 'Lavender Viscose Hijab', 'أحجبة وخمار', 'Hijabs', 16, 36, 30, 52, 10],
    ['شيلة خليجية مطرزة بالخيط', 'Thread Embroidered Gulf Sheila', 'أحجبة وخمار', 'Hijabs', 45, 98, 83, 25, 5],
    ['نقاب قطني ناعم', 'Soft Cotton Niqab', 'أحجبة وخمار', 'Hijabs', 15, 34, 28, 55, 10],
    ['نقاب شيفون مزدوج', 'Double Chiffon Niqab', 'أحجبة وخمار', 'Hijabs', 20, 44, 37, 48, 9],
    ['حجاب موندال بأربع ألوان', 'Four Color Mondal Hijab', 'أحجبة وخمار', 'Hijabs', 22, 50, 42, 50, 10],
    ['حجاب جاكار فاخر', 'Luxury Jacquard Hijab', 'أحجبة وخمار', 'Hijabs', 38, 85, 72, 30, 6],
    ['خمار جيرسيه بكاب', 'Jersey Cap Khimar', 'أحجبة وخمار', 'Hijabs', 35, 76, 64, 32, 7],
    ['شال كشمير دافئ', 'Warm Cashmere Shawl', 'أحجبة وخمار', 'Hijabs', 65, 142, 120, 20, 4],
    ['حجاب بفرقعة براق', 'Sparkling Snap Hijab', 'أحجبة وخمار', 'Hijabs', 25, 56, 47, 42, 8],
    ['حجاب مغربي مربوط', 'Moroccan Tied Hijab', 'أحجبة وخمار', 'Hijabs', 28, 62, 52, 38, 7],
    ['طرحة خليجية بيضاء ناعمة', 'Soft White Gulf Tarha', 'أحجبة وخمار', 'Hijabs', 30, 66, 56, 35, 7],
    ['حجاب نمط باتيك اندونيسي', 'Indonesian Batik Hijab', 'أحجبة وخمار', 'Hijabs', 26, 58, 49, 38, 8],

    // ═══ ملابس داخلية وبيتية (25) ════════════════════════════════════════════
    ['قميص نوم قطني فاتح', 'Light Cotton Nightgown', 'ملابس بيتية', 'Homewear', 40, 89, 75, 25, 5],
    ['بيجاما قطنية زهرية', 'Floral Cotton Pajama', 'ملابس بيتية', 'Homewear', 50, 110, 93, 22, 4],
    ['ثوب بيت ساتان', 'Satin House Gown', 'ملابس بيتية', 'Homewear', 60, 130, 110, 18, 4],
    ['بيجاما شتوية دافئة', 'Warm Winter Pajama', 'ملابس بيتية', 'Homewear', 70, 150, 128, 15, 3],
    ['قميص نوم دانتيل', 'Lace Nightgown', 'ملابس بيتية', 'Homewear', 55, 118, 100, 18, 4],
    ['بيجاما شيفون صيفية', 'Summer Chiffon Pajama', 'ملابس بيتية', 'Homewear', 45, 98, 83, 22, 5],
    ['روب حمام قطني', 'Cotton Bathrobe', 'ملابس بيتية', 'Homewear', 55, 119, 101, 20, 4],
    ['سلة منزلية قطنية', 'Cotton Home Set', 'ملابس بيتية', 'Homewear', 65, 140, 119, 16, 3],
    ['طقم بيجاما ثلاثي قطع', '3-Piece Pajama Set', 'ملابس بيتية', 'Homewear', 85, 184, 157, 14, 3],
    ['جلسة منزلية بكاب', 'Home Set with Cap', 'ملابس بيتية', 'Homewear', 70, 152, 129, 16, 3],
    ['شورت قطني حريمي', 'Women Cotton Shorts', 'ملابس بيتية', 'Homewear', 25, 56, 47, 35, 7],
    ['تانك توب قطني', 'Cotton Tank Top', 'ملابس بيتية', 'Homewear', 18, 40, 34, 40, 8],
    ['ليقه قطنية طويلة', 'Long Cotton Legging', 'ملابس بيتية', 'Homewear', 22, 48, 41, 38, 7],
    ['بنطلون بيتي قطني', 'Cotton Home Pants', 'ملابس بيتية', 'Homewear', 30, 65, 55, 30, 6],
    ['جاكيت قطني خفيف', 'Light Cotton Jacket', 'ملابس بيتية', 'Homewear', 45, 98, 83, 22, 5],
    ['كارديجان منزلي ناعم', 'Soft Home Cardigan', 'ملابس بيتية', 'Homewear', 50, 108, 92, 20, 4],
    ['بيجاما ترندي بطباعة', 'Trendy Print Pajama', 'ملابس بيتية', 'Homewear', 55, 118, 100, 18, 4],
    ['روب دفء قصير', 'Short Warm Robe', 'ملابس بيتية', 'Homewear', 42, 92, 78, 22, 5],
    ['قميص نوم أطفال', 'Kids Nightgown', 'ملابس بيتية', 'Homewear', 28, 60, 51, 30, 6],
    ['بيجاما أطفال بطباعة', 'Kids Print Pajama', 'ملابس بيتية', 'Homewear', 35, 76, 64, 28, 5],
    ['شراب نوم دافئة', 'Warm Sleep Socks', 'ملابس بيتية', 'Homewear', 10, 24, 19, 60, 12],
    ['بنطلون صالة رياضي', 'Sport Hall Pants', 'ملابس بيتية', 'Homewear', 35, 76, 64, 30, 6],
    ['تيشيرت قطني داخلي', 'Cotton Inner T-Shirt', 'ملابس بيتية', 'Homewear', 15, 33, 28, 50, 10],
    ['طقم منزلي كامل', 'Full Home Set', 'ملابس بيتية', 'Homewear', 95, 205, 174, 14, 3],
    ['بيجاما ساتان رياشي', 'Satin Ruffle Pajama', 'ملابس بيتية', 'Homewear', 65, 140, 119, 15, 3],

    // ═══ إكسسوارات (25) ═════════════════════════════════════════════════════
    ['حقيبة يد جلدية ناعمة', 'Soft Leather Handbag', 'إكسسوارات', 'Accessories', 80, 175, 149, 20, 4],
    ['حقيبة تسوق كبيرة', 'Large Shopping Bag', 'إكسسوارات', 'Accessories', 45, 98, 83, 25, 5],
    ['محفظة نسائية أنيقة', 'Elegant Women Wallet', 'إكسسوارات', 'Accessories', 35, 76, 64, 30, 6],
    ['بروش ذهبي عباية', 'Gold Abaya Brooch', 'إكسسوارات', 'Accessories', 20, 45, 38, 40, 8],
    ['دبوس حجاب بلورة', 'Crystal Hijab Pin', 'إكسسوارات', 'Accessories', 8, 18, 15, 80, 15],
    ['مشبك شعر فضي', 'Silver Hair Clip', 'إكسسوارات', 'Accessories', 12, 28, 23, 60, 12],
    ['سوار ذهبي خفيف', 'Light Gold Bracelet', 'إكسسوارات', 'Accessories', 25, 55, 46, 35, 7],
    ['عطر نسائي فواح', 'Fragrant Ladies Perfume', 'إكسسوارات', 'Accessories', 40, 88, 74, 25, 5],
    ['عود العطر الدهني', 'Oud Oil Perfume', 'إكسسوارات', 'Accessories', 55, 120, 102, 20, 4],
    ['نظارة شمس مودرن', 'Modern Sunglasses', 'إكسسوارات', 'Accessories', 30, 65, 55, 30, 6],
    ['أسورة منسجمة مع العباية', 'Abaya Matching Bracelet', 'إكسسوارات', 'Accessories', 22, 48, 41, 35, 7],
    ['قفازات جلدية رفيعة', 'Thin Leather Gloves', 'إكسسوارات', 'Accessories', 28, 62, 52, 25, 5],
    ['مفتاح هدية بالاسم', 'Name Gift Keychain', 'إكسسوارات', 'Accessories', 10, 24, 20, 50, 10],
    ['إطار لوحة تذكارية', 'Souvenir Frame', 'إكسسوارات', 'Accessories', 18, 40, 34, 30, 6],
    ['حقيبة ظهر نسائية', 'Women Backpack', 'إكسسوارات', 'Accessories', 60, 130, 110, 18, 4],
    ['حقيبة كروس بودي جلدية', 'Leather Crossbody Bag', 'إكسسوارات', 'Accessories', 70, 152, 129, 16, 3],
    ['إيشارب حريري مطبوع', 'Printed Silk Scarf', 'إكسسوارات', 'Accessories', 30, 66, 56, 28, 5],
    ['قبعة شمسية أنيقة', 'Elegant Sun Hat', 'إكسسوارات', 'Accessories', 25, 56, 47, 25, 5],
    ['طقم مجوهرات اصطناعي', 'Artificial Jewelry Set', 'إكسسوارات', 'Accessories', 35, 76, 64, 22, 4],
    ['مرآة يد مطلية بالذهب', 'Gold Plated Hand Mirror', 'إكسسوارات', 'Accessories', 20, 45, 38, 30, 6],
    ['شال برجوازي صوف', 'Bourgeois Wool Shawl', 'إكسسوارات', 'Accessories', 45, 98, 83, 22, 5],
    ['حذاء جلد ناعم نسائي', "Women's Soft Leather Shoe", 'إكسسوارات', 'Accessories', 80, 175, 149, 18, 4],
    ['صندل مفتوح أنيق', 'Elegant Open Sandal', 'إكسسوارات', 'Accessories', 55, 119, 101, 20, 4],
    ['كيس قماش مع طباعة', 'Printed Fabric Tote', 'إكسسوارات', 'Accessories', 15, 33, 28, 45, 9],
    ['علبة مجوهرات مخملية', 'Velvet Jewelry Box', 'إكسسوارات', 'Accessories', 30, 66, 56, 20, 4],

    // ═══ ملابس أطفال (25) ════════════════════════════════════════════════════
    ['فستان طفلة ورود', 'Girls Flower Dress', 'ملابس أطفال', "Children's Wear", 35, 76, 64, 25, 5],
    ['عباية طفلة صغيرة', 'Little Girls Abaya', 'ملابس أطفال', "Children's Wear", 55, 119, 101, 20, 4],
    ['بيجاما طفل كرتون', 'Cartoon Kids Pajama', 'ملابس أطفال', "Children's Wear", 28, 60, 51, 30, 6],
    ['بدلة طفل رسمية', 'Kids Formal Suit', 'ملابس أطفال', "Children's Wear", 65, 140, 119, 15, 3],
    ['تيشيرت طفل قطني', 'Kids Cotton T-Shirt', 'ملابس أطفال', "Children's Wear", 15, 34, 28, 45, 9],
    ['بنطلون جينز أطفال', 'Kids Jeans Pants', 'ملابس أطفال', "Children's Wear", 25, 55, 46, 35, 7],
    ['فستان حفلة طفلة', 'Kids Party Dress', 'ملابس أطفال', "Children's Wear", 75, 162, 138, 14, 3],
    ['طقم طفلة كامل', 'Full Girl Set', 'ملابس أطفال', "Children's Wear", 80, 172, 147, 13, 3],
    ['حجاب طفلة صغيرة', 'Small Girl Hijab', 'ملابس أطفال', "Children's Wear", 10, 22, 18, 50, 10],
    ['سترة أطفال شتوية', 'Kids Winter Jacket', 'ملابس أطفال', "Children's Wear", 60, 130, 110, 15, 3],
    ['قميص طفل مشجر', 'Kids Checkered Shirt', 'ملابس أطفال', "Children's Wear", 22, 48, 41, 35, 7],
    ['شورت أطفال قصير', 'Kids Short Shorts', 'ملابس أطفال', "Children's Wear", 15, 34, 28, 40, 8],
    ['حذاء رياضي أطفال', 'Kids Sports Shoes', 'ملابس أطفال', "Children's Wear", 45, 98, 83, 20, 4],
    ['كاب أطفال مطرزة', 'Kids Embroidered Cap', 'ملابس أطفال', "Children's Wear", 12, 28, 23, 40, 8],
    ['بدلة مدرسة طفلة', 'School Uniform Girl', 'ملابس أطفال', "Children's Wear", 50, 108, 92, 18, 4],
    ['طقم رياضي أطفال', 'Kids Sports Set', 'ملابس أطفال', "Children's Wear", 40, 88, 74, 22, 5],
    ['فستان طفلة عيد', 'Eid Girl Dress', 'ملابس أطفال', "Children's Wear", 90, 194, 165, 12, 3],
    ['عباية طفلة بترتر', 'Glitter Girl Abaya', 'ملابس أطفال', "Children's Wear", 70, 152, 129, 14, 3],
    ['سروال ليقه طفلة', 'Girl Legging Pants', 'ملابس أطفال', "Children's Wear", 18, 40, 34, 35, 7],
    ['سويتر أطفال محبوك', 'Kids Knit Sweater', 'ملابس أطفال', "Children's Wear", 35, 76, 64, 25, 5],
    ['بنطلون كارجو أطفال', 'Kids Cargo Pants', 'ملابس أطفال', "Children's Wear", 30, 66, 56, 28, 5],
    ['جاكيت فرو أطفال', 'Kids Fur Jacket', 'ملابس أطفال', "Children's Wear", 55, 120, 102, 16, 3],
    ['حقيبة ظهر مدرسة', 'School Backpack', 'ملابس أطفال', "Children's Wear", 35, 76, 64, 22, 4],
    ['بيبي بودي سوت', 'Baby Bodysuit', 'ملابس أطفال', "Children's Wear", 20, 44, 37, 40, 8],
    ['طقم بيبي كامل', 'Full Baby Set', 'ملابس أطفال', "Children's Wear", 55, 120, 102, 18, 4],

    // ═══ عطور ومستحضرات (35) ════════════════════════════════════════════════
    ['عطر ورد الطائف', 'Taif Rose Perfume', 'عطور', 'Fragrances', 60, 135, 114, 20, 4],
    ['عطر عود ليلي فاخر', 'Luxury Layla Oud', 'عطور', 'Fragrances', 120, 260, 221, 15, 3],
    ['بخور دهني عربي', 'Arabic Oud Bakhoor', 'عطور', 'Fragrances', 45, 98, 83, 25, 5],
    ['عود بالعود الملكي', 'Royal Oud Attar', 'عطور', 'Fragrances', 150, 325, 277, 12, 2],
    ['كريم عطري مرطب', 'Fragrant Moisturizer', 'عطور', 'Fragrances', 30, 65, 55, 30, 6],
    ['زيت الأرغان الطبيعي', 'Natural Argan Oil', 'عطور', 'Fragrances', 40, 88, 74, 25, 5],
    ['روائح منزلية معطرة', 'Home Fragrance Spray', 'عطور', 'Fragrances', 25, 55, 46, 35, 7],
    ['دهن عود سيالك', 'Seyalak Oud Oil', 'عطور', 'Fragrances', 80, 175, 149, 18, 4],
    ['بخور مجمر شجري', 'Wood Bakhoor Charcoal', 'عطور', 'Fragrances', 35, 76, 64, 25, 5],
    ['عطر إيقاع الليل', 'Night Rhythm Perfume', 'عطور', 'Fragrances', 70, 152, 129, 18, 4],
    ['مطيب هواء سيارة', 'Car Air Freshener', 'عطور', 'Fragrances', 12, 28, 23, 50, 10],
    ['غسول جسم معطر', 'Scented Body Wash', 'عطور', 'Fragrances', 20, 44, 37, 35, 7],
    ['لوشن بعد الاستحمام', 'After Shower Lotion', 'عطور', 'Fragrances', 22, 48, 41, 32, 6],
    ['مزيل العرق رول أون', 'Deodorant Roll On', 'عطور', 'Fragrances', 10, 24, 20, 60, 12],
    ['بودرة تلك معطرة', 'Fragrant Talc Powder', 'عطور', 'Fragrances', 15, 34, 28, 45, 9],
    ['زيت شعر بالزيتون', 'Olive Hair Oil', 'عطور', 'Fragrances', 18, 40, 34, 40, 8],
    ['قناع وجه بالعسل', 'Honey Face Mask', 'عطور', 'Fragrances', 25, 55, 46, 30, 6],
    ['سيروم وجه هيالورونيك', 'Hyaluronic Face Serum', 'عطور', 'Fragrances', 55, 120, 102, 20, 4],
    ['كريم نهار مرطب', 'Day Moisturizer Cream', 'عطور', 'Fragrances', 40, 88, 74, 25, 5],
    ['كريم ليل مغذي', 'Night Nourishing Cream', 'عطور', 'Fragrances', 45, 98, 83, 22, 4],
    ['مقشر جسم طبيعي', 'Natural Body Scrub', 'عطور', 'Fragrances', 28, 62, 52, 28, 5],
    ['صابون ورد طبيعي', 'Natural Rose Soap', 'عطور', 'Fragrances', 8, 18, 15, 70, 14],
    ['صابون عسل يدوي', 'Handmade Honey Soap', 'عطور', 'Fragrances', 10, 22, 18, 65, 13],
    ['زبدة شيا مرطبة', 'Moisturizing Shea Butter', 'عطور', 'Fragrances', 22, 48, 41, 30, 6],
    ['أمبول شعر مكثف', 'Intensive Hair Ampoule', 'عطور', 'Fragrances', 15, 34, 28, 50, 10],
    ['ماء ورد تونر', 'Rose Water Toner', 'عطور', 'Fragrances', 18, 40, 34, 40, 8],
    ['غسول وجه مزيل', 'Facial Cleanser Wash', 'عطور', 'Fragrances', 25, 55, 46, 35, 7],
    ['واقي شمس SPF50', 'SPF50 Sunscreen', 'عطور', 'Fragrances', 35, 76, 64, 28, 5],
    ['بلسم شفاه عطري', 'Scented Lip Balm', 'عطور', 'Fragrances', 8, 18, 15, 80, 16],
    ['طلاء أظافر خليجي', 'Gulf Nail Polish', 'عطور', 'Fragrances', 10, 24, 20, 60, 12],
    ['ريمل عيون فضي', 'Silver Eye Kohl', 'عطور', 'Fragrances', 12, 28, 23, 55, 11],
    ['كحل عربي أصيل', 'Authentic Arabic Kohl', 'عطور', 'Fragrances', 10, 24, 20, 60, 12],
    ['ظلال عيون لامعة', 'Shimmery Eye Shadow', 'عطور', 'Fragrances', 20, 44, 37, 35, 7],
    ['روج شفاه دائم', 'Long Lasting Lipstick', 'عطور', 'Fragrances', 15, 34, 28, 45, 9],
    ['كريم مبيض اليدين', 'Hands Whitening Cream', 'عطور', 'Fragrances', 18, 40, 34, 38, 7],
];

// ── Insert products ────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    INSERT INTO products
        (name_ar, name_en, sku, category_id, cost_price, retail_price, wholesale_price, stock_qty, min_stock_alert, has_variants, image)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL)
");

$skuCounters = [];

foreach ($products as $p) {
    [$nameAr, $nameEn, $catAr, $catEn, $cost, $retail, $wholesale, $stock, $minStock] = $p;
    $catId = ensureCat($pdo, $catMap, $catAr, $catEn);

    // Build SKU prefix from category
    $prefix = mb_strtoupper(mb_substr($catEn, 0, 3));
    $skuCounters[$prefix] = ($skuCounters[$prefix] ?? 0) + 1;
    $skuCode = sku($prefix, $skuCounters[$prefix]);

    try {
        $stmt->execute([$nameAr, $nameEn, $skuCode, $catId, $cost, $retail, $wholesale, $stock, $minStock]);
        $inserted++;
    } catch (PDOException $e) {
        $skipped++;
    }
}

echo "<h2 style='font-family:sans-serif;direction:rtl;text-align:right;'>✅ تم إضافة <strong>$inserted</strong> منتج بنجاح — تجاوز $skipped منتج (مكرر أو خطأ)</h2>";
echo "<p style='font-family:sans-serif;direction:rtl;text-align:right;'>يمكنك حذف هذا الملف الآن من: <code>/config/seed_200_products.php</code></p>";
echo "<a href='" . BASE_URL . "/products/' style='font-family:sans-serif;'>← عرض المنتجات</a>";
