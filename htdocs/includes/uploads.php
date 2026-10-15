<?php
declare(strict_types=1);
/** رفع الصور (لوجو العملاء/الموردين، صور المستخدمين): تحقق من النوع الحقيقي + إعادة حفظ بحجم صغير */
const UPLOAD_MAX_BYTES = 3 * 1024 * 1024;

function upload_root(): string { return dirname(__DIR__) . '/uploads'; }

/** يحفظ الصورة المرفوعة في الخانة $field داخل uploads/$sub ويرجّع المسار النسبي، أو null لو مفيش ملف. يرمي AppError لو الصورة مرفوضة */
function save_uploaded_image(string $field, string $sub, int $maxSide = 480): ?string
{
    $f = $_FILES[$field] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) { return null; }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        throw new AppError(in_array($f['error'], [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true) ? 'الصورة كبيرة جداً (الحد 3 ميجا).' : 'تعذّر رفع الصورة، جرّب تاني.');
    }
    if ($f['size'] > UPLOAD_MAX_BYTES) { throw new AppError('الصورة كبيرة جداً (الحد 3 ميجا).'); }
    if (!is_uploaded_file($f['tmp_name'])) { throw new AppError('ملف غير صالح.'); }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'png'];
    if (!$info || !isset($types[$info[2]])) { throw new AppError('الملف لازم يكون صورة (JPG أو PNG أو WEBP أو GIF).'); }
    $ext = $types[$info[2]];
    $dir = upload_root() . '/' . $sub;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) { throw new AppError('تعذّر تجهيز فولدر الصور على السيرفر.'); }
    $name = bin2hex(random_bytes(10)) . '.' . $ext;
    $dest = $dir . '/' . $name;
    $done = false;
    if (extension_loaded('gd')) {   // إعادة حفظ: بتصغّر الصورة وبتشيل أي محتوى غريب جواها
        $src = match ($info[2]) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
                                  IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']), default => @imagecreatefromgif($f['tmp_name']) };
        if ($src) {
            [$w, $h] = [imagesx($src), imagesy($src)];
            $k = min(1, $maxSide / max($w, $h)); $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($h * $k));
            $dst = imagecreatetruecolor($nw, $nh);
            if ($ext !== 'jpg') { imagealphablending($dst, false); imagesavealpha($dst, true); imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127)); }
            else { imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); }
            imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
            $done = match ($ext) { 'jpg' => imagejpeg($dst, $dest, 88), 'png' => imagepng($dst, $dest, 7), default => imagewebp($dst, $dest, 88) };
        }
    }
    if (!$done && !move_uploaded_file($f['tmp_name'], $dest)) { throw new AppError('تعذّر حفظ الصورة.'); }
    @chmod($dest, 0644);
    return $sub . '/' . $name;
}

function delete_upload(?string $rel): void
{
    if (!$rel || !preg_match('#^(logos|avatars)/[a-f0-9]+\.(jpg|png|webp)$#', $rel)) { return; }
    @unlink(upload_root() . '/' . $rel);
}

function upload_url(?string $rel): string { return $rel ? url('uploads/' . $rel) : ''; }

/** صورة دائرية/مربعة: الصورة لو موجودة، وإلا أول حرف من الاسم */
function avatar_html(?string $rel, string $name, string $cls = ''): string
{
    $ini = e(mb_substr(trim($name) ?: '؟', 0, 1));
    if ($rel && is_file(upload_root() . '/' . $rel)) {
        return '<span class="avatar ' . e($cls) . ' has-img"><img src="' . e(upload_url($rel)) . '" alt=""></span>';
    }
    return '<span class="avatar ' . e($cls) . '">' . $ini . '</span>';
}
