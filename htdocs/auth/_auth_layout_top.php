<?php
/** قالب صفحات الدخول/النسيان (الجزء العلوي) */
$company = setting('company_name', APP_NAME);
?><!DOCTYPE html>
<html lang="ar" dir="rtl">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? 'تسجيل الدخول') ?> | <?= e($company) ?></title>
<meta name="theme-color" content="#1a68a8">
<?php pwa_head(); ?>
<link rel="icon" type="image/svg+xml" href="<?= e(url('assets/favicon.svg')) ?>">
<link rel="apple-touch-icon" href="<?= e(url('assets/img/apple-touch-icon.png')) ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.rtl.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>?v=<?= (int)@filemtime(dirname(__DIR__) . '/assets/css/app.css') ?>">
</head>
<body>
<div class="auth-page">
  <span class="orb o1"></span><span class="orb o2"></span><span class="orb o3"></span>
  <div class="auth-card">
    <div class="top"><img src="<?= e(url('assets/img/logo-full.svg')) ?>" alt="<?= e($company) ?>"></div>
    <div class="title"><?= e($page_title ?? 'تسجيل الدخول') ?></div>
