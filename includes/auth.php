<?php
define('DATA_DIR', dirname(__DIR__) . '/data/');
define('CONFIG_FILE', DATA_DIR . 'config.json');

require_once __DIR__ . '/data.php';

function auth_start(): void {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}

function is_logged_in(): bool {
    auth_start();
    return !empty($_SESSION['admin_logged_in']);
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: ' . admin_url('index.php'));
        exit;
    }
}

function do_login(string $username, string $password): bool {
    $config = get_config();
    foreach ($config['users'] as $user) {
        if ($user['username'] === $username && password_verify($password, $user['password_hash'])) {
            auth_start();
            $_SESSION['admin_logged_in'] = true;
            $_SESSION['admin_user']      = $username;
            return true;
        }
    }
    return false;
}

function do_logout(): void {
    auth_start();
    session_destroy();
}

function current_user(): string {
    return $_SESSION['admin_user'] ?? '';
}

function admin_url(string $page = ''): string {
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    // resolve to /admin/ path
    if (strpos($base, '/admin') !== false) {
        $adminBase = $base;
    } else {
        $adminBase = $base . '/admin';
    }
    return $adminBase . ($page ? '/' . ltrim($page, '/') : '/');
}
