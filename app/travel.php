<?php

require_once 'config.php';
require_login();
$title = 'Travel';
$main_class = 'main-content';
$current_user_id = (int)($_SESSION['uid'] ?? 0);
$is_admin = has_permission('admin.access');
$can_manage_regions = has_permission('admin.regions.manage');
require __DIR__ . '/views/travel.view.php';
