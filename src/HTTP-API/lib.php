<?php
// Shared helpers for the dashboard (index.php, api.php): session auth + DB.
declare(strict_types=1);
require __DIR__ . '/config.php';

if (!defined('DASHBOARD_PASSWORD_HASH') || DASHBOARD_PASSWORD_HASH === '') {
    http_response_code(503);
    exit('Dashboard disabled: set DASHBOARD_PASSWORD_HASH in config.php');
}

function session_begin(): void {
    session_name('paxdash');
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => dirname($_SERVER['SCRIPT_NAME']) . '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
}

function logged_in(): bool {
    return ($_SESSION['auth'] ?? false) === true;
}

function csrf_token(): string {
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function db(): PDO {
    $pdo = new PDO(DB_DSN, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    // all DATETIME columns are UTC; make UNIX_TIMESTAMP() agree
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
