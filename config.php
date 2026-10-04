<?php
declare(strict_types=1);

return [
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => getenv('DB_PORT') ?: '3306',
    'db_name' => getenv('DB_NAME') ?: 'gorev_hatirlatici',
    'db_user' => getenv('DB_USER') ?: 'root',
    'db_password' => getenv('DB_PASSWORD') ?: '',
    'admin_user' => getenv('ADMIN_USER') ?: 'admin',
    'admin_password' => getenv('ADMIN_PASSWORD') ?: '',
    'app_url' => getenv('APP_URL') ?: 'http://localhost',
    'smtp_host' => getenv('SMTP_HOST') ?: '',
    'smtp_port' => getenv('SMTP_PORT') ?: '587',
    'smtp_encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
    'smtp_username' => getenv('SMTP_USERNAME') ?: '',
    'smtp_password' => getenv('SMTP_PASSWORD') ?: '',
    'smtp_from_email' => getenv('SMTP_FROM_EMAIL') ?: '',
    'smtp_from_name' => getenv('SMTP_FROM_NAME') ?: 'Görev Hatırlatıcısı',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Europe/Istanbul',
];
