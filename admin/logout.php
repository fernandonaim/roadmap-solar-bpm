<?php
require_once dirname(__DIR__) . '/includes/auth.php';
do_logout();
header('Location: index.php');
exit;
