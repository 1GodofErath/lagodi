<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
// Запобігання прямому доступу до файлу
if (!defined('SECURITY_CHECK')) {
    die('Прямий доступ до цього файлу заборонений');
}

/**
 * Клас для роботи з базою даних
 * Використовує шаблон Singleton для забезпечення єдиного з'єднання з БД
 * Адаптовано до структури бази даних Lagodi - 2025-04-06
 */
class Database {
    private static $instance = null;
    private $connection;
    private $statement;
    private $transactionCount = 0;

    /**
     * Конструктор класу, встановлює з'єднання з базою даних
     */
    private function __construct() {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false
            ];
            $this->connection = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            logError("Database connection error: " . $e->getMessage());
            throw new Exception("Помилка підключення до бази даних. Будь ласка, спробуйте пізніше.");
        }
    }

    /**
     * Повертає екземпляр класу Database (реалізація шаблону Singleton)
     */
    public static function getInstance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Повертає об'єкт PDO для з'єднання з базою даних
     */
    public function getConnection() {
        return $this->connection;
    }

    /**
     * Виконує SQL-запит з параметрами
     * @param string $sql SQL-запит
     * @param array $params Масив параметрів для запиту
     * @return Database Поточний екземпляр для ланцюгових викликів
     */
    public function query($sql, $params = []) {
        try {
            // Перевіряємо, чи запит стосується form_fields
            $isFormFieldsQuery = (strpos($sql, 'form_fields') !== false);
            $isUserSettingsQuery = (strpos($sql, 'user_settings') !== false);

            // Якщо це запит до form_fields і таблиця не існує, створюємо її
            if ($isFormFieldsQuery && !$this->tableExists('form_fields')) {
                logError("form_fields table missing. Creating tables...", "info");
                $this->createFormFieldsTable();
                $this->createFormsTable();
                $this->seedDefaultFormData();
            }

            // Якщо це запит до user_settings і таблиця не існує, створюємо її
            if ($isUserSettingsQuery && !$this->tableExists('user_settings')) {
                logError("user_settings table missing. Creating table...", "info");
                $this->createSettingsTable();
            }

            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute($params);
            return $this;
        } catch (PDOException $e) {
            $errorMessage = "Query execution error: " . $e->getMessage() . " | Query: $sql";

            // Безпечно виводимо параметри запиту (без конфіденційних даних)
            if (is_array($params) && !empty($params)) {
                $safeParams = [];
                foreach ($params as $key => $value) {
                    // Маскуємо конфіденційні дані
                    if (is_string($key) && (stripos($key, 'pass') !== false || stripos($key, 'token') !== false)) {
                        $safeParams[$key] = '***MASKED***';
                    } else {
                        $safeParams[$key] = is_string($value) ? mb_substr($value, 0, 30) . (mb_strlen($value) > 30 ? '...' : '') : $value;
                    }
                }
                $errorMessage .= " | Params: " . print_r($safeParams, true);
            }

            // Логуємо помилку докладніше
            logError($errorMessage);

            // Обробка особливих помилок
            if (strpos($e->getMessage(), "form_fields") !== false ||
                strpos($sql, "form_fields") !== false) {
                // Якщо це помилка з form_fields, створюємо таблиці та повертаємо стандартні дані
                logError("Handling form_fields query error gracefully", "warning");

                // Створюємо потрібні таблиці, якщо вони відсутні
                $this->createFormFieldsTable();
                $this->createFormsTable();
                $this->seedDefaultFormData();

                // Для запитів SELECT повертаємо порожній результат
                if (strpos(strtoupper($sql), "SELECT") === 0) {
                    $this->statement = null;
                    return $this;
                }

                // Для інших запитів виконуємо їх повторно
                try {
                    $this->statement = $this->connection->prepare($sql);
                    $this->statement->execute($params);
                    return $this;
                } catch (PDOException $e2) {
                    // Якщо все ще є помилка, просто логуємо і повертаємо нуль
                    logError("Still error after creating tables: " . $e2->getMessage());
                    $this->statement = null;
                    return $this;
                }
            }

            // Перевіряємо, чи існує таблиця
            if (strpos($e->getMessage(), "Base table or view not found") !== false ||
                strpos($e->getMessage(), "doesn't exist") !== false ||
                strpos($e->getMessage(), "Table") !== false) {

                // Отримуємо ім'я таблиці з повідомлення про помилку
                preg_match("/['\"](.+?)['\"]|Table (.+?) doesn/", $e->getMessage(), $matches);
                $tableName = isset($matches[1]) ? $matches[1] : (isset($matches[2]) ? $matches[2] : "unknown");

                logError("Missing table detected: $tableName", "warning");

                if ($tableName === "user_settings") {
                    $this->createSettingsTable();
                }
                else if ($tableName === "forms") {
                    $this->createFormsTable();
                }
                else if ($tableName === "form_fields") {
                    $this->createFormFieldsTable();
                    $this->seedDefaultFormData();
                }

                // Спробуємо виконати запит знову
                try {
                    $this->statement = $this->connection->prepare($sql);
                    $this->statement->execute($params);
                    return $this;
                } catch (PDOException $e2) {
                    // Якщо все ще є помилка, просто логуємо
                    logError("Still error after creating table $tableName: " . $e2->getMessage());
                }

                // Для запитів SELECT повертаємо порожній результат
                if (strpos(strtoupper($sql), "SELECT") === 0) {
                    logError("Returning empty result for SELECT query on missing table $tableName");
                    $this->statement = null;
                    return $this;
                }

                throw new Exception("Таблиця $tableName не знайдена в базі даних. Можливо, потрібно виконати міграції.");
            }

            // Перевіряємо, чи існує колонка
            if (strpos($e->getMessage(), "Unknown column") !== false) {
                // Отримуємо ім'я колонки з повідомлення про помилку
                preg_match("/Unknown column '(.*?)' in/", $e->getMessage(), $matches);
                $columnName = isset($matches[1]) ? $matches[1] : "unknown";

                // Для запитів SELECT повертаємо порожній результат
                if (strpos(strtoupper($sql), "SELECT") === 0) {
                    logError("Returning empty result for SELECT query with unknown column $columnName");
                    $this->statement = null;
                    return $this;
                }

                throw new Exception("Колонка $columnName не знайдена в таблиці. Можливо, структура бази даних застаріла.");
            }

            // Для запитів SELECT повертаємо порожній результат
            if (strpos(strtoupper($sql), "SELECT") === 0) {
                logError("Returning empty result for SELECT query due to error: " . $e->getMessage());
                $this->statement = null;
                return $this;
            }

            throw new Exception("Помилка виконання запиту до бази даних. Деталі можна знайти в журналі логів.");
        }
    }

    /**
     * Повертає всі рядки результату запиту
     */
    public function findAll() {
        if ($this->statement === null) {
            return [];
        }
        return $this->statement->fetchAll();
    }

    /**
     * Повертає перший рядок результату запиту
     */
    public function find() {
        if ($this->statement === null) {
            return false;
        }
        return $this->statement->fetch();
    }

    /**
     * Повертає значення першого стовпця першого рядка результату запиту
     */
    public function findColumn() {
        if ($this->statement === null) {
            return false;
        }
        return $this->statement->fetchColumn();
    }

    /**
     * Повертає кількість рядків, задіяних в останньому запиті
     */
    public function rowCount() {
        if ($this->statement === null) {
            return 0;
        }
        return $this->statement->rowCount();
    }

    /**
     * Повертає ID останнього вставленого рядка
     */
    public function lastInsertId() {
        return $this->connection->lastInsertId();
    }

    /**
     * Починає транзакцію
     */
    public function beginTransaction() {
        if ($this->transactionCount == 0) {
            $this->connection->beginTransaction();
        }
        $this->transactionCount++;
        return $this;
    }

    /**
     * Підтверджує транзакцію
     */
    public function commit() {
        if ($this->transactionCount == 1) {
            $this->connection->commit();
        }
        $this->transactionCount = max(0, $this->transactionCount - 1);
        return $this;
    }

    /**
     * Скасовує транзакцію
     */
    public function rollBack() {
        if ($this->transactionCount == 1) {
            $this->connection->rollBack();
        }
        $this->transactionCount = max(0, $this->transactionCount - 1);
        return $this;
    }

    /**
     * Запобігає клонуванню об'єкта (частина реалізації Singleton)
     */
    private function __clone() {}

    /**
     * Запобігає відновленню об'єкта зі стану серіалізації (частина реалізації Singleton)
     */
    public function __wakeup() {}

    /**
     * Перевіряє, чи існує таблиця в базі даних
     * @param string $tableName Назва таблиці
     * @return bool Результат перевірки
     */
    public function tableExists($tableName) {
        try {
            $sql = "SHOW TABLES LIKE ?";
            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute([$tableName]);
            return $this->statement->rowCount() > 0;
        } catch (PDOException $e) {
            logError("Error checking if table exists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Перевіряє, чи існує стовпець у таблиці
     * @param string $tableName Назва таблиці
     * @param string $columnName Назва стовпця
     * @return bool Результат перевірки
     */
    public function columnExists($tableName, $columnName) {
        try {
            $sql = "SHOW COLUMNS FROM $tableName LIKE ?";
            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute([$columnName]);
            return $this->statement->rowCount() > 0;
        } catch (PDOException $e) {
            logError("Error checking if column exists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Формує безпечний UPDATE запит, перевіряючи наявність стовпців
     * @param string $tableName Назва таблиці
     * @param array $columns Масив стовпців для оновлення у форматі [стовпець => значення]
     * @param string $whereClause Умова WHERE без слова WHERE
     * @param array $whereParams Параметри для умови WHERE
     * @return bool Результат виконання запиту
     */
    public function safeUpdate($tableName, $columns, $whereClause, $whereParams = []) {
        try {
            // Перевіряємо наявність таблиці
            if (!$this->tableExists($tableName)) {
                logError("Table $tableName does not exist");
                return false;
            }

            // Відфільтровуємо тільки існуючі стовпці
            $validColumns = [];
            foreach ($columns as $column => $value) {
                if ($this->columnExists($tableName, $column)) {
                    $validColumns[$column] = $value;
                } else {
                    logError("Column $column does not exist in table $tableName, skipping");
                }
            }

            if (empty($validColumns)) {
                logError("No valid columns to update in table $tableName");
                return false;
            }

            // Формуємо SQL запит
            $setClauses = [];
            $params = [];

            foreach ($validColumns as $column => $value) {
                $setClauses[] = "`$column` = ?";
                $params[] = $value;
            }

            $sql = "UPDATE $tableName SET " . implode(', ', $setClauses) . " WHERE $whereClause";

            // Додаємо параметри WHERE до загального масиву параметрів
            $params = array_merge($params, $whereParams);

            // Виконуємо запит
            $this->statement = $this->connection->prepare($sql);
            $this->statement->execute($params);

            return $this->statement->rowCount() > 0;
        } catch (PDOException $e) {
            logError("Safe update error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Створює таблицю налаштувань, якщо вона не існує
     * @return bool Результат створення таблиці
     */
    public function createSettingsTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS user_settings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                setting_key VARCHAR(100) NOT NULL,
                setting_value TEXT,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX (user_id),
                UNIQUE INDEX (user_id, setting_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            $this->connection->exec($sql);

            logError("Created user_settings table successfully", "info");
            return true;
        } catch (PDOException $e) {
            logError("Error creating settings table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Створює таблицю форм, якщо вона не існує
     * @return bool Результат створення таблиці
     */
    public function createFormsTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS forms (
                id INT AUTO_INCREMENT PRIMARY KEY,
                form_key VARCHAR(100) NOT NULL,
                form_name VARCHAR(255) NOT NULL,
                form_description TEXT NULL,
                form_type VARCHAR(50) NOT NULL DEFAULT 'general',
                submission_url VARCHAR(255) NULL,
                success_message TEXT NULL,
                failure_message TEXT NULL,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                UNIQUE INDEX (form_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            $this->connection->exec($sql);

            logError("Created forms table successfully", "info");
            return true;
        } catch (PDOException $e) {
            logError("Error creating forms table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Створює таблицю полів форм, якщо вона не існує
     * @return bool Результат створення таблиці
     */
    public function createFormFieldsTable() {
        try {
            $sql = "CREATE TABLE IF NOT EXISTS form_fields (
                id INT AUTO_INCREMENT PRIMARY KEY,
                form_id INT NOT NULL,
                field_key VARCHAR(100) NOT NULL,
                field_label VARCHAR(255) NOT NULL,
                field_type VARCHAR(50) NOT NULL,
                field_options TEXT NULL,
                is_required TINYINT(1) NOT NULL DEFAULT 0,
                validation_rules TEXT NULL,
                placeholder VARCHAR(255) NULL,
                default_value VARCHAR(255) NULL,
                sort_order INT NOT NULL DEFAULT 0,
                is_active TINYINT(1) NOT NULL DEFAULT 1,
                created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME NULL,
                INDEX (form_id),
                UNIQUE INDEX (form_id, field_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";

            $this->connection->exec($sql);

            logError("Created form_fields table successfully", "info");
            return true;
        } catch (PDOException $e) {
            logError("Error creating form_fields table: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Додає початкові дані у форми
     * @return bool Результат операції
     */
    public function seedDefaultFormData() {
        try {
            // Перевірка наявності форми для замовлення
            $checkSql = "SELECT id FROM forms WHERE form_key = 'order_form' LIMIT 1";
            $stmt = $this->connection->prepare($checkSql);
            $stmt->execute();

            if ($stmt->rowCount() === 0) {
                // Якщо форма не існує, створюємо її
                $this->beginTransaction();

                // Створення форми
                $formSql = "INSERT INTO forms (form_key, form_name, form_description, form_type, is_active, created_at) 
                            VALUES ('order_form', 'Форма замовлення', 'Стандартна форма для створення замовлень', 'order', 1, NOW())";
                $this->connection->exec($formSql);
                $formId = $this->lastInsertId();

                // Створення полів форми на основі вашої таблиці orders
                $fields = [
                    ['device_type', 'Тип пристрою', 'select', '{"options":["Телефон","Планшет","Ноутбук","ПК","МФУ","Телефон сенсорний","Телефон кнопковий"]}', 1, 10],
                    ['service', 'Послуга', 'select', '{"options":["Діагностика","Ремонт екрану","Заміна батареї","Чистка від пилу","Встановлення ПО"]}', 1, 20],
                    ['details', 'Опис проблеми', 'textarea', null, 1, 30],
                    ['phone', 'Контактний телефон', 'tel', null, 1, 40],
                    ['address', 'Адреса', 'text', null, 0, 50],
                    ['delivery_method', 'Спосіб доставки', 'radio', '{"options":["Самовивіз","Нова пошта","Кур\'єр"]}', 0, 60],
                    ['user_comment', 'Коментар', 'textarea', null, 0, 70],
                    ['first_name', 'Ім\'я', 'text', null, 0, 80],
                    ['last_name', 'Прізвище', 'text', null, 0, 90],
                    ['middle_name', 'По батькові', 'text', null, 0, 100]
                ];

                $fieldSql = "INSERT INTO form_fields 
                              (form_id, field_key, field_label, field_type, field_options, is_required, sort_order, is_active, created_at) 
                              VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW())";

                $stmt = $this->connection->prepare($fieldSql);
                foreach ($fields as $field) {
                    $stmt->execute([
                        $formId,
                        $field[0], // field_key
                        $field[1], // field_label
                        $field[2], // field_type
                        $field[3], // field_options
                        $field[4], // is_required
                        $field[5]  // sort_order
                    ]);
                }

                $this->commit();
                logError("Created default order form successfully", "info");
            }

            return true;
        } catch (PDOException $e) {
            if ($this->transactionCount > 0) {
                $this->rollBack();
            }
            logError("Error seeding default form data: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Перевірка наявності всіх необхідних таблиць
     * @return bool Результат перевірки
     */
    public function checkAndCreateTables() {
        $requiredTables = [
            'user_settings',
            'forms',
            'form_fields'
        ];

        $missingTables = [];
        foreach ($requiredTables as $table) {
            if (!$this->tableExists($table)) {
                $missingTables[] = $table;

                // Автоматично створюємо таблиці
                if ($table === 'user_settings') {
                    $this->createSettingsTable();
                } elseif ($table === 'forms') {
                    $this->createFormsTable();
                } elseif ($table === 'form_fields') {
                    $this->createFormFieldsTable();
                }
            }
        }

        // Додаємо початкові дані, якщо створені таблиці форм
        if (in_array('forms', $missingTables) || in_array('form_fields', $missingTables)) {
            $this->seedDefaultFormData();
        }

        if (!empty($missingTables)) {
            logError("Created missing database tables: " . implode(', ', $missingTables), "info");
        }

        return true;
    }

    /**
     * Виконує SQL запит напряму (без підготовки)
     * Використовувати тільки для запитів без параметрів!
     * @param string $sql SQL запит
     * @return bool Результат виконання
     */
    public function exec($sql) {
        try {
            return $this->connection->exec($sql);
        } catch (PDOException $e) {
            logError("Direct SQL execution error: " . $e->getMessage() . " | SQL: $sql");
            return false;
        }
    }
}