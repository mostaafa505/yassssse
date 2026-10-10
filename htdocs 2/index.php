<?php
require_once __DIR__ . '/config/config.php';

if (isLoggedIn()) {
    redirect('dashboard/');
} else {
    redirect('auth/login.php');
}
