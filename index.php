<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
]);
session_start();

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

$error = '';
$flash = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        global $config;
        $username = (string) ($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');
        if ($config['admin_password'] !== ''
            && hash_equals($config['admin_user'], $username)
            && hash_equals($config['admin_password'], $password)
        ) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            redirect('index.php');
        }
        $error = 'Kullanıcı adı veya parola hatalı. Yönetici parolası sunucu ayarlarında tanımlanmış olmalı.';
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_destroy();
        redirect('index.php');
    } elseif (empty($_SESSION['authenticated'])) {
        http_response_code(401);
        exit('Oturum açmanız gerekiyor.');
    } elseif ($action === 'add_task') {
        try {
            $title = trim((string) ($_POST['title'] ?? ''));
            $description = trim((string) ($_POST['description'] ?? ''));
            $dueInput = trim((string) ($_POST['due_at'] ?? ''));
            $recipient = trim((string) ($_POST['recipient_email'] ?? ''));
            $minutes = filter_var($_POST['reminder_minutes'] ?? null, FILTER_VALIDATE_INT);

            if ($title === '' || mb_strlen($title) > 200) {
                throw new InvalidArgumentException('Görev başlığı 1-200 karakter olmalıdır.');
            }
            if (mb_strlen($description) > 5000) {
                throw new InvalidArgumentException('Açıklama en fazla 5000 karakter olabilir.');
            }
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Geçerli bir hatırlatma e-posta adresi girin.');
            }
            if ($minutes === false || $minutes < 1 || $minutes > 43200) {
                throw new InvalidArgumentException('Hatırlatma süresi 1-43200 dakika arasında olmalıdır.');
            }

            $timezone = new DateTimeZone((string) settings()['timezone']);
            $due = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $dueInput, $timezone);
            $dateErrors = DateTimeImmutable::getLastErrors();
            if (!$due || ($dateErrors !== false && ($dateErrors['warning_count'] > 0 || $dateErrors['error_count'] > 0))
                || $due->format('Y-m-d\TH:i') !== $dueInput
            ) {
                throw new InvalidArgumentException('Geçerli bir son tarih ve saat seçin.');
            }
            $utc = new DateTimeZone('UTC');
            if ($due <= new DateTimeImmutable('now', $timezone)) {
                throw new InvalidArgumentException('Son tarih gelecekte olmalıdır.');
            }

            $reminder = $due->modify(sprintf('-%d minutes', $minutes));
            $insert = db()->prepare(
                'INSERT INTO tasks (title, description, due_at, reminder_at, reminder_minutes, recipient_email)
                 VALUES (:title, :description, :due_at, :reminder_at, :minutes, :recipient)'
            );
            $insert->execute([
                'title' => $title,
                'description' => $description,
                'due_at' => $due->setTimezone($utc)->format('Y-m-d H:i:s'),
                'reminder_at' => $reminder->setTimezone($utc)->format('Y-m-d H:i:s'),
                'minutes' => $minutes,
                'recipient' => $recipient,
            ]);
            $flash = 'Görev kaydedildi. Kayıtlar düzenlenemez veya silinemez.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $error = 'Görev kaydedilemedi. Veritabanı yapılandırmasını kontrol edin.';
        }
    } elseif ($action === 'save_settings') {
        try {
            $values = [
                'smtp_host' => trim((string) ($_POST['smtp_host'] ?? '')),
                'smtp_port' => trim((string) ($_POST['smtp_port'] ?? '')),
                'smtp_encryption' => (string) ($_POST['smtp_encryption'] ?? ''),
                'smtp_username' => trim((string) ($_POST['smtp_username'] ?? '')),
                'smtp_from_email' => trim((string) ($_POST['smtp_from_email'] ?? '')),
                'smtp_from_name' => trim((string) ($_POST['smtp_from_name'] ?? '')),
                'timezone' => (string) ($_POST['timezone'] ?? ''),
            ];
            $password = (string) ($_POST['smtp_password'] ?? '');
            if ($password !== '') {
                $values['smtp_password'] = $password;
            }

            if ($values['smtp_host'] === '' || strlen($values['smtp_host']) > 253) {
                throw new InvalidArgumentException('SMTP sunucu adresi gereklidir.');
            }
            if (!filter_var($values['smtp_host'], FILTER_VALIDATE_IP)
                && !filter_var($values['smtp_host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
            ) {
                throw new InvalidArgumentException('Geçerli bir SMTP sunucu adı girin.');
            }
            if (!ctype_digit($values['smtp_port']) || (int) $values['smtp_port'] < 1 || (int) $values['smtp_port'] > 65535) {
                throw new InvalidArgumentException('SMTP portu 1-65535 arasında olmalıdır.');
            }
            if (!in_array($values['smtp_encryption'], ['tls', 'ssl', 'none'], true)) {
                throw new InvalidArgumentException('SMTP şifreleme türünü seçin.');
            }
            if (!filter_var($values['smtp_from_email'], FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Geçerli bir gönderici e-posta adresi girin.');
            }
            if ($values['smtp_from_name'] === '' || strlen($values['smtp_from_name']) > 200) {
                throw new InvalidArgumentException('Gönderici adı gereklidir.');
            }
            new DateTimeZone($values['timezone']);

            $save = db()->prepare(
                'INSERT INTO app_settings (setting_key, setting_value) VALUES (:key, :value)
                 ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
            );
            foreach ($values as $key => $value) {
                $save->execute(['key' => $key, 'value' => $value]);
            }
            $flash = 'Hatırlatma ve SMTP ayarları kaydedildi.';
        } catch (InvalidArgumentException $exception) {
            $error = $exception->getMessage();
        } catch (Throwable $exception) {
            $error = 'Ayarlar kaydedilemedi. Veritabanı yapılandırmasını kontrol edin.';
        }
    }
}

$authenticated = !empty($_SESSION['authenticated']);
$currentSettings = [];
$tasks = [];
$totalTasks = 0;
$search = trim((string) ($_GET['q'] ?? ''));
$page = max(1, filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT) ?: 1);
$pageSize = 50;

if ($authenticated) {
    try {
        $currentSettings = settings();
        if (isset($_GET['saved'])) {
            $flash = (string) $_GET['saved'] === '1' ? 'Görev kaydedildi.' : '';
        }
        $where = '';
        $params = [];
        if ($search !== '') {
            $where = ' WHERE title LIKE :title OR description LIKE :description OR recipient_email LIKE :recipient';
            $term = '%' . addcslashes($search, '%_\\') . '%';
            $params = ['title' => $term, 'description' => $term, 'recipient' => $term];
        }
        $count = db()->prepare('SELECT COUNT(*) FROM tasks' . $where);
        $count->execute($params);
        $totalTasks = (int) $count->fetchColumn();

        $query = db()->prepare(
            'SELECT tasks.*, reminder_deliveries.status AS delivery_status
             FROM tasks LEFT JOIN reminder_deliveries ON reminder_deliveries.task_id = tasks.id'
             . $where . ' ORDER BY tasks.created_at DESC, tasks.id DESC LIMIT :limit OFFSET :offset'
        );
        foreach ($params as $name => $value) {
            $query->bindValue(':' . $name, $value, PDO::PARAM_STR);
        }
        $query->bindValue(':limit', $pageSize, PDO::PARAM_INT);
        $query->bindValue(':offset', ($page - 1) * $pageSize, PDO::PARAM_INT);
        $query->execute();
        $tasks = $query->fetchAll();
    } catch (Throwable $exception) {
        $error = 'Veritabanına bağlanılamadı. Ayarları ve schema.sql kurulumunu kontrol edin.';
    }
}
?>
<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Görev Hatırlatıcısı</title>
    <style>
        body{font:16px system-ui,sans-serif;max-width:1050px;margin:2rem auto;padding:0 1rem;color:#202938}
        h1,h2{color:#16324f} form,section,.task{border:1px solid #d6dde5;border-radius:8px;padding:1rem;margin:1rem 0}
        label{display:block;font-weight:600;margin:.7rem 0 .2rem} input,textarea,select,button{font:inherit;padding:.55rem;max-width:100%}
        input:not([type=checkbox]),textarea,select{width:100%;box-sizing:border-box} button{cursor:pointer;background:#145da0;color:white;border:0;border-radius:4px}
        .row{display:grid;grid-template-columns:1fr 1fr;gap:1rem}.notice{padding:.7rem;background:#e9f6ec}.error{padding:.7rem;background:#fde8e7}
        .muted{color:#5c6775}.top{display:flex;justify-content:space-between;align-items:center}.task h3{margin:.1rem 0}
        @media(max-width:650px){.row{grid-template-columns:1fr}.top{align-items:flex-start}}
    </style>
</head>
<body>
<header class="top">
    <h1>Görev Hatırlatıcısı</h1>
    <?php if ($authenticated): ?>
        <form method="post"><input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="logout"><button type="submit">Çıkış</button>
        </form>
    <?php endif; ?>
</header>

<?php if ($error !== ''): ?><p class="error"><?= e($error) ?></p><?php endif; ?>
<?php if ($flash !== ''): ?><p class="notice"><?= e($flash) ?></p><?php endif; ?>

<?php if (!$authenticated): ?>
    <form method="post">
        <h2>Yönetici girişi</h2>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="login">
        <label for="username">Kullanıcı adı</label><input id="username" name="username" autocomplete="username" required>
        <label for="password">Parola</label><input id="password" type="password" name="password" autocomplete="current-password" required>
        <p class="muted">Giriş için sunucuda ADMIN_USER ve ADMIN_PASSWORD tanımlanmalıdır.</p>
        <button type="submit">Giriş yap</button>
    </form>
<?php else: ?>
    <?php if (is_local_installation()): ?>
        <p class="notice">Yerel kurulum algılandı: e-posta hatırlatmaları gönderilmez.</p>
    <?php endif; ?>

    <section>
        <h2>Yeni görev ekle</h2>
        <p class="muted">Görevler kalıcıdır; bu panelden düzenlenemez veya silinemez.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="add_task">
            <label for="title">Görev başlığı</label><input id="title" name="title" maxlength="200" required>
            <label for="description">Açıklama</label><textarea id="description" name="description" maxlength="5000" rows="3"></textarea>
            <div class="row">
                <div><label for="due_at">Son tarih ve saat (<?= e((string) ($currentSettings['timezone'] ?? 'Europe/Istanbul')) ?>)</label>
                    <input id="due_at" type="datetime-local" name="due_at" required></div>
                <div><label for="reminder_minutes">Ne kadar önce hatırlatılsın?</label>
                    <select id="reminder_minutes" name="reminder_minutes">
                        <option value="5">5 dakika</option><option value="15">15 dakika</option>
                        <option value="30">30 dakika</option><option value="60" selected>1 saat</option>
                        <option value="1440">1 gün</option><option value="10080">1 hafta</option>
                    </select></div>
            </div>
            <label for="recipient_email">Hatırlatma e-posta adresi</label>
            <input id="recipient_email" type="email" name="recipient_email" maxlength="254" required>
            <button type="submit">Görevi kaydet</button>
        </form>
    </section>

    <section>
        <h2>SMTP ve hatırlatma ayarları</h2>
        <p class="muted">SMTP parolası veritabanında saklanır. Bu formu yalnızca HTTPS üzerinden kullanın.</p>
        <form method="post">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="save_settings">
            <div class="row">
                <div><label for="smtp_host">SMTP sunucusu</label><input id="smtp_host" name="smtp_host" value="<?= e((string) ($currentSettings['smtp_host'] ?? '')) ?>" required></div>
                <div><label for="smtp_port">SMTP portu</label><input id="smtp_port" type="number" min="1" max="65535" name="smtp_port" value="<?= e((string) ($currentSettings['smtp_port'] ?? '587')) ?>" required></div>
            </div>
            <div class="row">
                <div><label for="smtp_encryption">Şifreleme</label><select id="smtp_encryption" name="smtp_encryption">
                    <?php foreach (['tls' => 'STARTTLS', 'ssl' => 'SSL/TLS', 'none' => 'Şifreleme yok'] as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= ($currentSettings['smtp_encryption'] ?? 'tls') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select></div>
                <div><label for="timezone">Saat dilimi</label><input id="timezone" name="timezone" value="<?= e((string) ($currentSettings['timezone'] ?? 'Europe/Istanbul')) ?>" required></div>
            </div>
            <div class="row">
                <div><label for="smtp_username">SMTP kullanıcı adı</label><input id="smtp_username" name="smtp_username" value="<?= e((string) ($currentSettings['smtp_username'] ?? '')) ?>"></div>
                <div><label for="smtp_password">SMTP parolası</label><input id="smtp_password" type="password" name="smtp_password" autocomplete="new-password" placeholder="Boş bırakılırsa mevcut parola korunur"></div>
            </div>
            <div class="row">
                <div><label for="smtp_from_email">Gönderen e-posta adresi</label><input id="smtp_from_email" type="email" name="smtp_from_email" value="<?= e((string) ($currentSettings['smtp_from_email'] ?? '')) ?>" required></div>
                <div><label for="smtp_from_name">Gönderen adı</label><input id="smtp_from_name" name="smtp_from_name" value="<?= e((string) ($currentSettings['smtp_from_name'] ?? 'Görev Hatırlatıcısı')) ?>" required></div>
            </div>
            <button type="submit">Ayarları kaydet</button>
        </form>
    </section>

    <section>
        <h2>Görev kayıtları (<?= $totalTasks ?>)</h2>
        <form method="get">
            <label for="q">Görevlerde ara</label>
            <div class="row"><input id="q" name="q" value="<?= e($search) ?>" placeholder="Başlık, açıklama veya e-posta">
                <button type="submit">Ara</button></div>
        </form>
        <?php if ($tasks === []): ?><p>Görev bulunamadı.</p><?php endif; ?>
        <?php foreach ($tasks as $task): ?>
            <article class="task">
                <h3>#<?= (int) $task['id'] ?> — <?= e($task['title']) ?></h3>
                <?php if ($task['description'] !== ''): ?><p><?= nl2br(e($task['description'])) ?></p><?php endif; ?>
                <p>Son tarih: <?= e(display_datetime($task['due_at'])) ?> · Hatırlatma: <?= (int) $task['reminder_minutes'] ?> dakika önce</p>
                <p>E-posta: <?= e($task['recipient_email']) ?> · Oluşturulma: <?= e(display_datetime($task['created_at'])) ?></p>
                <p>Hatırlatma durumu:
                    <?php if (is_local_installation()): ?>Yerel ortamda devre dışı
                    <?php elseif ($task['delivery_status'] === 'sent'): ?>Gönderildi
                    <?php elseif ($task['delivery_status'] === 'failed'): ?>Gönderim hatası — yeniden denenecek
                    <?php else: ?>Bekliyor<?php endif; ?>
                </p>
            </article>
        <?php endforeach; ?>
        <?php if ($totalTasks > $pageSize): ?>
            <nav aria-label="Sayfalar">
                <?php if ($page > 1): ?><a href="?q=<?= rawurlencode($search) ?>&amp;page=<?= $page - 1 ?>">Önceki</a><?php endif; ?>
                Sayfa <?= $page ?> / <?= (int) ceil($totalTasks / $pageSize) ?>
                <?php if ($page * $pageSize < $totalTasks): ?><a href="?q=<?= rawurlencode($search) ?>&amp;page=<?= $page + 1 ?>">Sonraki</a><?php endif; ?>
            </nav>
        <?php endif; ?>
    </section>
<?php endif; ?>
</body>
</html>
