CREATE TABLE IF NOT EXISTS tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT NOT NULL,
    due_at DATETIME NOT NULL,
    reminder_at DATETIME NOT NULL,
    reminder_minutes INT UNSIGNED NOT NULL,
    recipient_email VARCHAR(254) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_tasks_reminder (reminder_at, due_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS app_settings (
    setting_key VARCHAR(64) NOT NULL PRIMARY KEY,
    setting_value TEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reminder_deliveries (
    task_id BIGINT UNSIGNED NOT NULL PRIMARY KEY,
    status ENUM('sending', 'sent', 'failed') NOT NULL,
    claimed_at DATETIME NOT NULL,
    sent_at DATETIME NULL,
    error_message TEXT NULL,
    CONSTRAINT fk_reminder_task FOREIGN KEY (task_id) REFERENCES tasks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

DELIMITER //
CREATE TRIGGER tasks_prevent_update
BEFORE UPDATE ON tasks
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Task records are immutable';
END//

CREATE TRIGGER tasks_prevent_delete
BEFORE DELETE ON tasks
FOR EACH ROW
BEGIN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Task records are permanent';
END//
DELIMITER ;
