<?php
// Константа для перевірки прямого доступу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Перевірка схеми БД
 * @param Database $db Екземпляр класу Database
 * @return bool Результат перевірки
 */
function checkDatabaseSchema($db) {
    // Масив очікуваних таблиць і їхніх обов'язкових полів
    $schema = [
        'notifications' => ['id', 'user_id', 'order_id', 'type', 'content', 'is_read', 'created_at'],
        'orders' => ['id', 'user_id', 'service', 'details', 'status', 'created_at'],
        'comments' => ['id', 'order_id', 'user_id', 'content', 'created_at'],
        'admin_notifications' => ['id', 'order_id', 'type', 'content', 'is_read', 'created_at'],
        'users' => ['id', 'username', 'email', 'password', 'role', 'created_at'],
        'user_sessions' => ['id', 'user_id', 'session_token', 'ip_address', 'created_at', 'expires_at', 'last_activity'],
        'user_activity_logs' => ['id', 'user_id', 'action', 'created_at'],
        'order_comments' => ['id', 'order_id', 'user_id', 'comment', 'created_at'],
        'order_files' => ['id', 'order_id', 'file_name', 'file_path', 'uploaded_at'],
        'order_history' => ['id', 'order_id', 'user_id', 'previous_status', 'new_status', 'changed_at']
    ];

    $issues = [];

    foreach ($schema as $table => $columns) {
        if (!$db->tableExists($table)) {
            $issues[] = "Відсутня таблиця: $table";
            continue;
        }

        foreach ($columns as $column) {
            if (!$db->columnExists($table, $column)) {
                $issues[] = "Відсутній стовпець: $column в таблиці $table";
            }
        }
    }

    if (!empty($issues)) {
        // Записуємо помилки в лог
        foreach ($issues as $issue) {
            logError("DB Schema issue: $issue");
        }
        return false;
    }

    return true;
}

/**
 * Функція для автоматичного вирішення проблем зі структурою БД
 * @param Database $db Екземпляр класу Database
 * @return bool Результат виконання
 */
function fixDatabaseSchemaIssues($db) {
    $issues = [];

    // Перевірка на існування таблиці notifications
    if (!$db->tableExists('notifications')) {
        $issues[] = "Створення таблиці notifications";

        $sql = "CREATE TABLE IF NOT EXISTS notifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            order_id INT NOT NULL,
            type VARCHAR(20) NOT NULL,
            title VARCHAR(255) NOT NULL,
            content TEXT NULL,
            old_status VARCHAR(50) NULL,
            new_status VARCHAR(50) NULL,
            is_read TINYINT(1) DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            description TEXT NULL,
            comment_author VARCHAR(100) NULL,
            service VARCHAR(100) NULL,
            comment_id INT NULL,
            INDEX (user_id),
            INDEX (order_id),
            INDEX (is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $db->exec($sql);
    }

    // Перевірка на існування таблиці user_activity_logs
    if (!$db->tableExists('user_activity_logs')) {
        $issues[] = "Створення таблиці user_activity_logs";

        $sql = "CREATE TABLE IF NOT EXISTS user_activity_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            action VARCHAR(255) NOT NULL,
            entity_type VARCHAR(50) NULL,
            entity_id INT NULL,
            details TEXT NULL,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

        $db->exec($sql);
    }

    if (!empty($issues)) {
        // Записуємо дії в лог
        foreach ($issues as $issue) {
            logError("DB Schema fix: $issue", "info");
        }
    }

    return true;
}

// Перевірка та виправлення проблем зі схемою БД
function initializeDatabase($db) {
    $isSchemaValid = checkDatabaseSchema($db);

    if (!$isSchemaValid) {
        logError("Database schema has issues, attempting to fix", "warning");
        fixDatabaseSchemaIssues($db);
    }

    return true;
}