<?php
// Forward view.php to index.php with the same parameters
$qs = $_SERVER['QUERY_STRING'] ?? '';
$target = 'index.php' . ($qs !== '' ? '?' . $qs : '');
header("Location: $target");
exit;
