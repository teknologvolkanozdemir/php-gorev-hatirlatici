<?php
declare(strict_types=1);

$config = require __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo;
    global $config;

    if (!$pdo) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['db_host'],
            $config['db_port'],
            $config['db_name']
        );
        $pdo = new PDO($dsn, $config['db_user'], $config['db_password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '+00:00'");
    }

    return $pdo;
}

function settings(): array
{
    static $cachedSettings;
    global $config;

    if ($cachedSettings !== null) {
        return $cachedSettings;
    }

    $settings = [
        'smtp_host' => $config['smtp_host'],
        'smtp_port' => $config['smtp_port'],
        'smtp_encryption' => $config['smtp_encryption'],
        'smtp_username' => $config['smtp_username'],
        'smtp_password' => $config['smtp_password'],
        'smtp_from_email' => $config['smtp_from_email'],
        'smtp_from_name' => $config['smtp_from_name'],
        'timezone' => $config['timezone'],
    ];

    foreach (db()->query('SELECT setting_key, setting_value FROM app_settings') as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }

    $cachedSettings = $settings;
    return $cachedSettings;
}

function is_local_installation(): bool
{
    global $config;
    $url = $config['app_url'];
    if (PHP_SAPI !== 'cli' && !empty($_SERVER['HTTP_HOST'])) {
        $url = 'http://' . $_SERVER['HTTP_HOST'];
    }

    $host = strtolower((string) parse_url($url, PHP_URL_HOST));
    return $host === ''
        || $host === 'localhost'
        || $host === '127.0.0.1'
        || $host === '::1'
        || str_ends_with($host, '.localhost')
        || str_ends_with($host, '.test');
}

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function require_csrf(): void
{
    if (!isset($_POST['csrf_token']) || !hash_equals(csrf_token(), (string) $_POST['csrf_token'])) {
        http_response_code(419);
        exit('İstek doğrulanamadı. Sayfayı yenileyip tekrar deneyin.');
    }
}

function display_datetime(string $utcDate): string
{
    $timezone = new DateTimeZone((string) (settings()['timezone'] ?? 'Europe/Istanbul'));
    return (new DateTimeImmutable($utcDate, new DateTimeZone('UTC')))
        ->setTimezone($timezone)
        ->format('d.m.Y H:i');
}
