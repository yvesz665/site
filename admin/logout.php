<?php
require_once __DIR__ . '/../includes/auth.php';

logoutUser();

header('Location: /admin/login.php');
exit;
