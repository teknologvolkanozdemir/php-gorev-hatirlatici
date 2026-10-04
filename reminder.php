<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

if (is_local_installation()) {
    fwrite(STDOUT, "Local installation detected; reminders are disabled.\n");
    exit(0);
}

function smtp_expect($socket, array $expected): string
{
    $response = '';
    do {
        $line = fgets($socket, 515);
        if ($line === false) {
            throw new RuntimeException('SMTP server closed the connection.');
        }
        $response .= $line;
    } while (isset($line[3]) && $line[3] === '-');

    $code = (int) substr($line, 0, 3);
    if (!in_array($code, $expected, true)) {
        throw new RuntimeException('SMTP rejected the request: ' . trim($response));
    }
    return $response;
}

function smtp_command($socket, string $command, array $expected): string
{
    smtp_write_all($socket, $command . "\r\n");
    return smtp_expect($socket, $expected);
}

function smtp_write_all($socket, string $data): void
{
    while ($data !== '') {
        $written = fwrite($socket, $data);
        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not write to SMTP server.');
        }
        $data = substr($data, $written);
    }
}

function send_smtp_message(array $settings, array $task): void
{
    global $config;

    if ($settings['smtp_host'] === '' || $settings['smtp_from_email'] === '') {
        throw new RuntimeException('SMTP host and sender address must be configured.');
    }
    if (!filter_var($settings['smtp_host'], FILTER_VALIDATE_IP)
        && !filter_var($settings['smtp_host'], FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)
    ) {
        throw new RuntimeException('Invalid SMTP server hostname.');
    }
    if (!filter_var($settings['smtp_from_email'], FILTER_VALIDATE_EMAIL)
        || !filter_var($task['recipient_email'], FILTER_VALIDATE_EMAIL)
    ) {
        throw new RuntimeException('Invalid sender or recipient email address.');
    }

    $host = (string) $settings['smtp_host'];
    $port = (int) $settings['smtp_port'];
    $transport = $settings['smtp_encryption'] === 'ssl' ? 'ssl://' : 'tcp://';
    $socketHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? '[' . $host . ']' : $host;
    $context = stream_context_create([
        'ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'peer_name' => $host],
    ]);
    $socket = @stream_socket_client(
        $transport . $socketHost . ':' . $port,
        $errno,
        $errstr,
        20,
        STREAM_CLIENT_CONNECT,
        $context
    );
    if (!$socket) {
        throw new RuntimeException('SMTP connection failed: ' . $errstr);
    }

    stream_set_timeout($socket, 20);
    try {
        smtp_expect($socket, [220]);
        $serverName = preg_replace('/[^a-zA-Z0-9.-]/', '', (string) parse_url($config['app_url'], PHP_URL_HOST)) ?: 'localhost';
        smtp_command($socket, 'EHLO ' . $serverName, [250]);

        if ($settings['smtp_encryption'] === 'tls') {
            smtp_command($socket, 'STARTTLS', [220]);
            if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                throw new RuntimeException('Could not enable TLS for SMTP.');
            }
            smtp_command($socket, 'EHLO ' . $serverName, [250]);
        }

        if ($settings['smtp_username'] !== '') {
            smtp_command($socket, 'AUTH LOGIN', [334]);
            smtp_command($socket, base64_encode((string) $settings['smtp_username']), [334]);
            smtp_command($socket, base64_encode((string) $settings['smtp_password']), [235]);
        }

        smtp_command($socket, 'MAIL FROM:<' . $settings['smtp_from_email'] . '>', [250]);
        smtp_command($socket, 'RCPT TO:<' . $task['recipient_email'] . '>', [250, 251]);
        smtp_command($socket, 'DATA', [354]);

        $subject = '=?UTF-8?B?' . base64_encode('Görev hatırlatması: ' . $task['title']) . '?=';
        $fromName = '=?UTF-8?B?' . base64_encode((string) $settings['smtp_from_name']) . '?=';
        $dueAt = (new DateTimeImmutable($task['due_at'], new DateTimeZone('UTC')))
            ->setTimezone(new DateTimeZone((string) $settings['timezone']))
            ->format('d.m.Y H:i T');
        $body = "Merhaba,\n\n"
            . "Görev zamanı yaklaşıyor: " . $task['title'] . "\n"
            . "Son tarih: " . $dueAt . "\n";
        if ($task['description'] !== '') {
            $body .= "\nAçıklama:\n" . $task['description'] . "\n";
        }
        $body .= "\nBu e-posta görev hatırlatıcısı tarafından gönderilmiştir.\n";
        $headers = [
            'From: ' . $fromName . ' <' . $settings['smtp_from_email'] . '>',
            'To: <' . $task['recipient_email'] . '>',
            'Subject: ' . $subject,
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
        ];
        $message = implode("\r\n", $headers) . "\r\n\r\n"
            . chunk_split(base64_encode($body), 76, "\r\n");
        $message = preg_replace('/(?m)^\./', '..', $message);
        smtp_write_all($socket, $message . ".\r\n");
        smtp_expect($socket, [250]);
        smtp_command($socket, 'QUIT', [221]);
    } finally {
        fclose($socket);
    }
}

try {
    $pdo = db();
    $query = $pdo->query(
        "SELECT t.* FROM tasks t
         LEFT JOIN reminder_deliveries d ON d.task_id = t.id
         WHERE t.reminder_at <= UTC_TIMESTAMP() AND t.due_at >= UTC_TIMESTAMP()
           AND (d.task_id IS NULL OR
                (d.status <> 'sent' AND d.claimed_at <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE))
         ORDER BY t.reminder_at ASC LIMIT 100"
    );
    $pendingTasks = $query->fetchAll();
    $currentSettings = settings();
    $sent = 0;
    $failed = 0;

    foreach ($pendingTasks as $task) {
        $claim = $pdo->prepare(
            "INSERT IGNORE INTO reminder_deliveries (task_id, status, claimed_at)
             VALUES (:task_id, 'sending', UTC_TIMESTAMP())"
        );
        $claim->execute(['task_id' => $task['id']]);
        $claimed = $claim->rowCount() === 1;

        if (!$claimed) {
            $retry = $pdo->prepare(
                "UPDATE reminder_deliveries SET status = 'sending', claimed_at = UTC_TIMESTAMP(), error_message = NULL
                 WHERE task_id = :task_id AND status <> 'sent'
                   AND claimed_at <= UTC_TIMESTAMP() - INTERVAL 15 MINUTE"
            );
            $retry->execute(['task_id' => $task['id']]);
            $claimed = $retry->rowCount() === 1;
        }
        if (!$claimed) {
            continue;
        }

        try {
            send_smtp_message($currentSettings, $task);
            $complete = $pdo->prepare(
                "UPDATE reminder_deliveries SET status = 'sent', sent_at = UTC_TIMESTAMP(), error_message = NULL
                 WHERE task_id = :task_id"
            );
            $complete->execute(['task_id' => $task['id']]);
            $sent++;
        } catch (Throwable $exception) {
            $fail = $pdo->prepare(
                "UPDATE reminder_deliveries SET status = 'failed', claimed_at = UTC_TIMESTAMP(), error_message = :error
                 WHERE task_id = :task_id"
            );
            $fail->execute([
                'error' => substr($exception->getMessage(), 0, 2000),
                'task_id' => $task['id'],
            ]);
            fwrite(STDERR, 'Task #' . $task['id'] . ': ' . $exception->getMessage() . "\n");
            $failed++;
        }
    }

    fwrite(STDOUT, sprintf("Reminder run complete: %d sent, %d failed.\n", $sent, $failed));
    exit($failed > 0 ? 1 : 0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Reminder run failed: ' . $exception->getMessage() . "\n");
    exit(1);
}
