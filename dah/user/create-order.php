<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Підключення необхідних файлів
require_once '../../dah/confi/database.php';
require_once '../../dah/include/session.php';
require_once '../../dah/include/functions.php';
require_once '../../dah/include/auth.php';

// Перевірка авторизації
if (!isLoggedIn()) {
    header("Location: /login.php");
    exit;
}

// Отримання поточного користувача
$user = getCurrentUser();

// Перевірка, чи заблокований користувач
if (isUserBlocked($user['id'])) {
    header("Location: /logout.php?reason=blocked");
    exit;
}

// Встановлення теми
if (isset($_GET['theme'])) {
    $theme = $_GET['theme'] === 'dark' ? 'dark' : 'light';
    setcookie('theme', $theme, time() + (86400 * 30), "/"); // 30 днів
    $_COOKIE['theme'] = $theme; // Встановлюємо значення одразу для поточного запиту

    // Отримуємо поточний URL
    $currentUrl = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

    // Перенаправлення назад на поточну сторінку без параметра theme
    $queryParams = $_GET;
    unset($queryParams['theme']);

    $queryString = '';
    if (!empty($queryParams)) {
        $queryString = '?' . http_build_query($queryParams);
    }

    header("Location: $currentUrl$queryString");
    exit;
}

$currentTheme = isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'dark'; // Темна тема за замовчуванням

// База даних
$database = new Database();
$db = $database->getConnection();

// Ініціалізація змінних для повідомлень
$successMessage = '';
$errorMessage = '';

// Перевіряємо і створюємо таблицю order_files, якщо вона не існує
try {
    // Перевіряємо існування таблиці order_files
    $checkTableQuery = "SHOW TABLES LIKE 'order_files'";
    $checkTableStmt = $db->prepare($checkTableQuery);
    $checkTableStmt->execute();

    if ($checkTableStmt->rowCount() === 0) {
        // Таблиця не існує, створюємо її
        $createTableQuery = "CREATE TABLE order_files (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            filename VARCHAR(255) NOT NULL,
            original_name VARCHAR(255),
            file_path VARCHAR(255),
            uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($createTableQuery);
    } else {
        // Таблиця існує, перевіряємо наявність необхідних стовпців
        $requiredFileColumns = [
            'order_id' => 'ALTER TABLE order_files ADD COLUMN order_id INT NOT NULL',
            'filename' => 'ALTER TABLE order_files ADD COLUMN filename VARCHAR(255) NOT NULL',
            'original_name' => 'ALTER TABLE order_files ADD COLUMN original_name VARCHAR(255)',
            'file_path' => 'ALTER TABLE order_files ADD COLUMN file_path VARCHAR(255)',
            'uploaded_at' => 'ALTER TABLE order_files ADD COLUMN uploaded_at DATETIME DEFAULT CURRENT_TIMESTAMP'
        ];

        // Перевіряємо і додаємо кожен стовпець
        foreach ($requiredFileColumns as $column => $addColumnSql) {
            $checkColumnQuery = "SHOW COLUMNS FROM order_files LIKE '$column'";
            $checkColumnStmt = $db->prepare($checkColumnQuery);
            $checkColumnStmt->execute();

            if ($checkColumnStmt->rowCount() === 0) {
                $db->exec($addColumnSql);
            }
        }
    }

    // Перевіряємо існування таблиці comments
    $checkCommentsTableQuery = "SHOW TABLES LIKE 'comments'";
    $checkCommentsTableStmt = $db->prepare($checkCommentsTableQuery);
    $checkCommentsTableStmt->execute();

    if ($checkCommentsTableStmt->rowCount() === 0) {
        // Таблиця не існує, створюємо її
        $createCommentsTableQuery = "CREATE TABLE comments (
            id INT AUTO_INCREMENT PRIMARY KEY,
            order_id INT NOT NULL,
            user_id INT NOT NULL,
            content TEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )";
        $db->exec($createCommentsTableQuery);
    }
} catch (PDOException $e) {
    $errorMessage = "Помилка при перевірці/створенні структури таблиці order_files: " . $e->getMessage();
}

// Додаємо відсутні стовпці до таблиці orders
try {
    // Список необхідних стовпців
    $requiredColumns = [
        'category_id' => 'ALTER TABLE orders ADD COLUMN category_id INT DEFAULT NULL',
        'description' => 'ALTER TABLE orders ADD COLUMN description TEXT',
        'device_type' => 'ALTER TABLE orders ADD COLUMN device_type VARCHAR(100)',
        'device_model' => 'ALTER TABLE orders ADD COLUMN device_model VARCHAR(100)',
        'device_serial' => 'ALTER TABLE orders ADD COLUMN device_serial VARCHAR(100)',
        'phone' => 'ALTER TABLE orders ADD COLUMN phone VARCHAR(20)'
    ];

    // Перевіряємо і додаємо кожен стовпець
    foreach ($requiredColumns as $column => $addColumnSql) {
        // Перевіряємо, чи існує стовпець
        $checkColumnQuery = "SHOW COLUMNS FROM orders LIKE '$column'";
        $checkColumnStmt = $db->prepare($checkColumnQuery);
        $checkColumnStmt->execute();

        // Якщо стовпця немає, додаємо його
        if ($checkColumnStmt->rowCount() === 0) {
            $db->exec($addColumnSql);
        }
    }
} catch (PDOException $e) {
    $errorMessage = "Помилка перевірки або оновлення структури таблиці: " . $e->getMessage();
}

// Виправлене завантаження категорій з урахуванням можливої відсутності стовпця 'icon'
try {
    // Спочатку перевіримо структуру таблиці
    $checkColumnQuery = "SHOW COLUMNS FROM service_categories LIKE 'icon'";
    $checkColumnStmt = $db->prepare($checkColumnQuery);
    $checkColumnStmt->execute();
    $iconColumnExists = $checkColumnStmt->rowCount() > 0;

    // Базовий запит для отримання категорій
    $categoriesQuery = $iconColumnExists
        ? "SELECT id, name, icon FROM service_categories ORDER BY name"
        : "SELECT id, name FROM service_categories ORDER BY name";

    $categoriesStmt = $db->prepare($categoriesQuery);
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);

    // Якщо категорій немає, додаємо демо-категорії
    if (empty($categories)) {
        $demoCategories = [
            ['name' => 'Ремонт комп\'ютерів', 'icon' => 'bi-laptop'],
            ['name' => 'Ремонт ноутбуків', 'icon' => 'bi-laptop'],
            ['name' => 'Ремонт смартфонів', 'icon' => 'bi-phone'],
            ['name' => 'Ремонт планшетів', 'icon' => 'bi-tablet'],
            ['name' => 'Налаштування програм', 'icon' => 'bi-gear'],
            ['name' => 'Встановлення Windows', 'icon' => 'bi-windows'],
            ['name' => 'Чистка від вірусів', 'icon' => 'bi-bug'],
            ['name' => 'Відновлення даних', 'icon' => 'bi-hdd'],
            ['name' => 'Модернізація ПК', 'icon' => 'bi-pc-display'],
            ['name' => 'Налаштування мережі', 'icon' => 'bi-wifi'],
            ['name' => 'Інше', 'icon' => 'bi-three-dots']
        ];

        // Перевіримо, чи існує таблиця
        $checkTableQuery = "SHOW TABLES LIKE 'service_categories'";
        $checkTableStmt = $db->prepare($checkTableQuery);
        $checkTableStmt->execute();

        if ($checkTableStmt->rowCount() > 0) {
            // Перевіримо, чи потрібно додати стовпець icon
            if (!$iconColumnExists) {
                try {
                    $addColumnQuery = "ALTER TABLE service_categories ADD COLUMN icon VARCHAR(50) DEFAULT 'bi-tag'";
                    $db->exec($addColumnQuery);
                    $iconColumnExists = true;
                } catch(PDOException $e) {
                    // Помилка додавання стовпця, продовжуємо без нього
                }
            }

            // Додаємо демо-категорії до бази даних
            foreach ($demoCategories as $category) {
                if ($iconColumnExists) {
                    $insertQuery = "INSERT INTO service_categories (name, icon) VALUES (:name, :icon)";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(':name', $category['name']);
                    $insertStmt->bindParam(':icon', $category['icon']);
                } else {
                    $insertQuery = "INSERT INTO service_categories (name) VALUES (:name)";
                    $insertStmt = $db->prepare($insertQuery);
                    $insertStmt->bindParam(':name', $category['name']);
                }
                $insertStmt->execute();
            }

            // Отримаємо знову всі категорії
            $categoriesStmt = $db->prepare($categoriesQuery);
            $categoriesStmt->execute();
            $categories = $categoriesStmt->fetchAll(PDO::FETCH_ASSOC);
        } else {
            // Якщо таблиці немає, використовуємо демо-категорії
            $categories = [];
            foreach ($demoCategories as $index => $category) {
                $categories[] = [
                    'id' => $index + 1,
                    'name' => $category['name'],
                    'icon' => $category['icon']
                ];
            }
        }
    }

    // Додаємо значення icon за замовчуванням, якщо стовпець відсутній
    if (!$iconColumnExists) {
        foreach ($categories as &$category) {
            // Призначаємо іконки за замовчуванням на основі назви категорії
            $name = mb_strtolower($category['name']);
            if (strpos($name, 'комп') !== false) {
                $category['icon'] = 'bi-laptop';
            } elseif (strpos($name, 'ноут') !== false) {
                $category['icon'] = 'bi-laptop';
            } elseif (strpos($name, 'смарт') !== false || strpos($name, 'телефон') !== false) {
                $category['icon'] = 'bi-phone';
            } elseif (strpos($name, 'план') !== false) {
                $category['icon'] = 'bi-tablet';
            } elseif (strpos($name, 'програм') !== false) {
                $category['icon'] = 'bi-gear';
            } elseif (strpos($name, 'windows') !== false) {
                $category['icon'] = 'bi-windows';
            } elseif (strpos($name, 'вірус') !== false) {
                $category['icon'] = 'bi-bug';
            } elseif (strpos($name, 'дан') !== false) {
                $category['icon'] = 'bi-hdd';
            } elseif (strpos($name, 'модерн') !== false || strpos($name, 'пк') !== false) {
                $category['icon'] = 'bi-pc-display';
            } elseif (strpos($name, 'мереж') !== false || strpos($name, 'wifi') !== false) {
                $category['icon'] = 'bi-wifi';
            } else {
                $category['icon'] = 'bi-tag';
            }
        }
        unset($category); // Розриваємо посилання
    }
} catch (PDOException $e) {
    $errorMessage = "Помилка завантаження категорій: " . $e->getMessage();

    // Якщо виникла помилка, встановимо демо-категорії
    $categories = [
        ['id' => 1, 'name' => 'Ремонт комп\'ютерів', 'icon' => 'bi-laptop'],
        ['id' => 2, 'name' => 'Ремонт ноутбуків', 'icon' => 'bi-laptop'],
        ['id' => 3, 'name' => 'Ремонт смартфонів', 'icon' => 'bi-phone'],
        ['id' => 4, 'name' => 'Ремонт планшетів', 'icon' => 'bi-tablet'],
        ['id' => 5, 'name' => 'Налаштування програм', 'icon' => 'bi-gear'],
        ['id' => 6, 'name' => 'Встановлення Windows', 'icon' => 'bi-windows'],
        ['id' => 7, 'name' => 'Чистка від вірусів', 'icon' => 'bi-bug'],
        ['id' => 8, 'name' => 'Відновлення даних', 'icon' => 'bi-hdd'],
        ['id' => 9, 'name' => 'Модернізація ПК', 'icon' => 'bi-pc-display'],
        ['id' => 10, 'name' => 'Налаштування мережі', 'icon' => 'bi-wifi'],
        ['id' => 11, 'name' => 'Інше', 'icon' => 'bi-three-dots']
    ];
}

// Обробка форми створення замовлення
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Перевірка, чи надіслано форму створення
    if (isset($_POST['service']) && isset($_POST['category_id']) && isset($_POST['description']) && isset($_POST['phone'])) {
        // Отримання даних з форми
        $service = htmlspecialchars($_POST['service'] ?? '');
        $category_id = filter_var($_POST['category_id'] ?? 0, FILTER_VALIDATE_INT);
        $description = htmlspecialchars($_POST['description'] ?? '');
        $device_type = htmlspecialchars($_POST['device_type'] ?? '');
        $device_model = htmlspecialchars($_POST['device_model'] ?? '');
        $device_serial = htmlspecialchars($_POST['device_serial'] ?? '');
        $phone = htmlspecialchars($_POST['phone'] ?? '');

        // Валідація форми
        $errors = [];

        if (empty($service)) {
            $errors[] = "Назва сервісу є обов'язковою";
        }

        if ($category_id <= 0) {
            $errors[] = "Виберіть категорію";
        }

        if (empty($description)) {
            $errors[] = "Опис проблеми є обов'язковим";
        }

        if (empty($phone)) {
            $errors[] = "Номер телефону є обов'язковий";
        } elseif (!preg_match("/^[0-9+\-\s()]{10,15}$/", $phone)) {
            $errors[] = "Невірний формат номера телефону";
        }

        if (empty($errors)) {
            try {
                // Початок транзакції
                $db->beginTransaction();

                // Простий запит для вставки даних
                $query = "INSERT INTO orders (user_id, service, category_id, description, device_type, device_model, device_serial, phone, status, created_at) 
                          VALUES (:user_id, :service, :category_id, :description, :device_type, :device_model, :device_serial, :phone, :status, NOW())";

                $stmt = $db->prepare($query);
                $stmt->bindParam(':user_id', $user['id']);
                $stmt->bindParam(':service', $service);
                $stmt->bindParam(':category_id', $category_id);
                $stmt->bindParam(':description', $description);
                $stmt->bindParam(':device_type', $device_type);
                $stmt->bindParam(':device_model', $device_model);
                $stmt->bindParam(':device_serial', $device_serial);
                $stmt->bindParam(':phone', $phone);
                $status = 'Новий';
                $stmt->bindParam(':status', $status);

                $stmt->execute();
                $orderId = $db->lastInsertId();

                // Додавання файлів, якщо вони є
                if (!empty($_FILES['attachments']['name'][0])) {
                    $uploadDir = '../../uploads/orders/' . $orderId . '/';
                    if (!file_exists($uploadDir)) {
                        mkdir($uploadDir, 0777, true);
                    }

                    foreach ($_FILES['attachments']['name'] as $key => $name) {
                        if ($_FILES['attachments']['error'][$key] === 0) {
                            $tmpName = $_FILES['attachments']['tmp_name'][$key];
                            $fileName = basename($name);
                            $fileExt = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

                            // Перевірка типу файлу
                            $allowedExts = ['jpg', 'jpeg', 'png', 'pdf', 'doc', 'docx'];
                            if (in_array($fileExt, $allowedExts)) {
                                $newFileName = uniqid() . '.' . $fileExt;
                                $uploadFile = $uploadDir . $newFileName;

                                if (move_uploaded_file($tmpName, $uploadFile)) {
                                    try {
                                        // Запис в БД
                                        $fileQuery = "INSERT INTO order_files (order_id, filename, original_name, file_path, uploaded_at) 
                                                     VALUES (:order_id, :filename, :original_name, :file_path, NOW())";
                                        $fileStmt = $db->prepare($fileQuery);
                                        $fileStmt->bindParam(':order_id', $orderId);
                                        $fileStmt->bindParam(':filename', $newFileName);
                                        $fileStmt->bindParam(':original_name', $fileName);
                                        $fileStmt->bindParam(':file_path', $uploadFile);
                                        $fileStmt->execute();
                                    } catch (PDOException $e) {
                                        // Якщо помилка при записі в БД - видаляємо файл
                                        if (file_exists($uploadFile)) {
                                            unlink($uploadFile);
                                        }
                                        throw $e; // Передаємо помилку вище для відкату транзакції
                                    }
                                }
                            }
                        }
                    }
                }

                // Додавання автоматичного коментаря про створення замовлення
                try {
                    $commentQuery = "INSERT INTO comments (order_id, user_id, content, is_read, created_at) 
                                    VALUES (:order_id, :user_id, 'Замовлення створено', 1, NOW())";
                    $commentStmt = $db->prepare($commentQuery);
                    $commentStmt->bindParam(':order_id', $orderId);
                    $commentStmt->bindParam(':user_id', $user['id']);
                    $commentStmt->execute();
                } catch (PDOException $e) {
                    // Ігноруємо помилки з коментарями, оскільки вони не критичні
                }

                // Завершення транзакції
                $db->commit();

                $successMessage = "Замовлення успішно створено! Номер вашого замовлення: " . $orderId;

                // Перенаправляємо на сторінку з деталями замовлення
                header("Location: orders.php?id=" . $orderId . "&success=created");
                exit;
            } catch (PDOException $e) {
                $db->rollback();
                $errorMessage = "Помилка при створенні замовлення: " . $e->getMessage();
            }
        } else {
            $errorMessage = "Будь ласка, виправте наступні помилки:<br>" . implode("<br>", $errors);
        }
    }
}

// Функція для безпечного виведення тексту
function safeEcho($text, $default = '') {
    return htmlspecialchars($text ?? $default, ENT_QUOTES, 'UTF-8');
}
?>

<!DOCTYPE html>
<html lang="uk" data-theme="<?php echo $currentTheme; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Нове замовлення - Lagodi Service</title>

    <!-- Блокуємо рендеринг сторінки до встановлення теми -->
    <script>
        // Блокуємо рендеринг сторінки до встановлення теми
        (function() {
            const storedTheme = localStorage.getItem('theme') || '<?php echo $currentTheme; ?>';
            document.documentElement.setAttribute('data-theme', storedTheme);
        })();
    </script>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../style/dahm/user_dashboard.css">
    <link rel="stylesheet" href="../../style/dahm/create_order.css">
</head>
<body>
<div class="wrapper" id="mainWrapper">
    <!-- Сайдбар (ліва панель) -->
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-header">
            <a href="/dah/dashboard.php" class="logo">Lagodi Service</a>
            <button id="sidebarToggle" class="toggle-btn">
                <i class="bi bi-arrow-left"></i>
            </button>
        </div>
        <div class="user-info">
            <div class="user-avatar">
                <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
            </div>
            <div class="user-details">
                <h3><?php echo safeEcho($user['username']); ?></h3>
                <p><?php echo safeEcho($user['email']); ?></p>
            </div>
            <a href="../user/profile.php" class="edit-profile-btn">Редагувати профіль</a>
        </div>
        <nav class="sidebar-nav">
            <a href="/dah/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i>
                <span>Дашборд</span>
            </a>
            <a href="/dah/user/create-order.php" class="nav-link active">
                <i class="bi bi-plus-circle"></i>
                <span>Нове замовлення</span>
            </a>
            <a href="/dah/user/orders.php" class="nav-link">
                <i class="bi bi-list-check"></i>
                <span>Мої замовлення</span>
            </a>
            <a href="/dah/user/notifications.php" class="nav-link">
                <i class="bi bi-bell"></i>
                <span>Коментарі</span>
            </a>
            <a href="/dah/user/profile.php" class="nav-link">
                <i class="bi bi-person"></i>
                <span>Профіль</span>
            </a>
            <a href="/dah/user/settings.php" class="nav-link">
                <i class="bi bi-gear"></i>
                <span>Налаштування</span>
            </a>
            <a href="/logout.php" class="nav-link logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Вихід</span>
            </a>
            <div class="nav-divider"></div>
            <div class="theme-switcher">
                <span class="theme-label">Тема:</span>
                <div class="theme-options">
                    <a href="?theme=light" class="theme-option <?php echo $currentTheme === 'light' ? 'active' : ''; ?>">
                        <i class="bi bi-sun"></i> Світла
                    </a>
                    <a href="?theme=dark" class="theme-option <?php echo $currentTheme === 'dark' ? 'active' : ''; ?>">
                        <i class="bi bi-moon"></i> Темна
                    </a>
                </div>
            </div>
        </nav>
    </aside>

    <!-- Основний контент -->
    <main class="main-content" id="mainContent">
        <header class="main-header">
            <button id="menuToggle" class="menu-toggle">
                <i class="bi bi-list"></i>
            </button>
            <div class="header-title">
                <h1>Створення нового замовлення</h1>
            </div>
            <div class="header-actions">
                <button id="themeSwitchBtn" class="theme-switch-btn">
                    <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                </button>

                <div class="user-dropdown">
                    <button class="user-dropdown-btn">
                        <div class="user-avatar-small">
                            <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                        </div>
                        <span class="user-name"><?php echo safeEcho($user['username']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu">
                        <a href="/dah/user/profile.php"><i class="bi bi-person"></i> Профіль</a>
                        <a href="/dah/user/settings.php"><i class="bi bi-gear"></i> Налаштування</a>
                        <div class="dropdown-divider"></div>
                        <a href="#" id="toggleThemeButton">
                            <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                            <?php echo $currentTheme === 'dark' ? 'Світла тема' : 'Темна тема'; ?>
                        </a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php"><i class="bi bi-box-arrow-right"></i> Вихід</a>
                    </div>
                </div>
            </div>
        </header>

        <div class="content-wrapper">
            <div class="section-container">
                <!-- Заголовок сторінки -->
                <div class="page-header">
                    <h2><i class="bi bi-plus-square"></i> Нове замовлення</h2>
                    <p>Заповніть форму нижче для створення нового замовлення на ремонт або обслуговування</p>
                </div>

                <?php if ($errorMessage): ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
                    </div>
                <?php endif; ?>

                <?php if ($successMessage): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> <?php echo $successMessage; ?>
                    </div>
                <?php endif; ?>

                <!-- Форма створення замовлення -->
                <div class="order-form-container">
                    <form id="createOrderForm" method="post" action="<?php echo htmlspecialchars($_SERVER['PHP_SELF']); ?>" enctype="multipart/form-data" class="order-form">
                        <div class="form-row">
                            <div class="form-group">
                                <label for="service">Назва сервісу / Тип послуги*</label>
                                <input type="text" id="service" name="service" required
                                       placeholder="Наприклад: Ремонт ноутбука, Заміна екрану"
                                       value="<?php echo isset($_POST['service']) ? safeEcho($_POST['service']) : ''; ?>">
                                <small>Коротко опишіть тип послуги, яка вам потрібна</small>
                            </div>

                            <div class="form-group">
                                <label for="category_id">Категорія*</label>
                                <div class="category-select-container">
                                    <select id="category_id" name="category_id" required>
                                        <option value="">Виберіть категорію</option>
                                        <?php foreach ($categories as $category): ?>
                                            <option value="<?php echo $category['id']; ?>"
                                                <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $category['id']) ? 'selected' : ''; ?>>
                                                <?php echo safeEcho($category['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <!-- Сюди JavaScript додасть стрілку -->
                                </div>
                                <small>Виберіть категорію, яка найбільше відповідає вашому запиту</small>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="description">Опис проблеми*</label>
                            <textarea id="description" name="description" rows="5" required
                                      placeholder="Детально опишіть проблему або запит..."><?php echo isset($_POST['description']) ? safeEcho($_POST['description']) : ''; ?></textarea>
                            <small>Надайте якомога більше деталей про проблему, з якою ви зіткнулися</small>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="device_type">Тип пристрою</label>
                                <input type="text" id="device_type" name="device_type"
                                       placeholder="Наприклад: Ноутбук, Смартфон, Планшет"
                                       value="<?php echo isset($_POST['device_type']) ? safeEcho($_POST['device_type']) : ''; ?>">
                                <small>Вкажіть тип пристрою, який потребує обслуговування</small>
                            </div>

                            <div class="form-group">
                                <label for="device_model">Модель пристрою</label>
                                <input type="text" id="device_model" name="device_model"
                                       placeholder="Наприклад: iPhone 12, MacBook Pro 2019"
                                       value="<?php echo isset($_POST['device_model']) ? safeEcho($_POST['device_model']) : ''; ?>">
                                <small>Вкажіть модель та рік випуску пристрою, якщо відомо</small>
                            </div>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label for="device_serial">Серійний номер</label>
                                <input type="text" id="device_serial" name="device_serial"
                                       placeholder="Серійний номер пристрою (за наявності)"
                                       value="<?php echo isset($_POST['device_serial']) ? safeEcho($_POST['device_serial']) : ''; ?>">
                                <small>Вкажіть серійний номер для точнішої ідентифікації пристрою</small>
                            </div>

                            <div class="form-group">
                                <label for="phone">Контактний телефон*</label>
                                <input type="tel" id="phone" name="phone" required
                                       placeholder="+380XXXXXXXXX"
                                       value="<?php echo isset($_POST['phone']) ? safeEcho($_POST['phone']) : ''; ?>">
                                <small>Вкажіть номер телефону для зв'язку з вами</small>
                            </div>
                        </div>

                        <div class="form-group full-width">
                            <label for="attachments">Прикріпити файли</label>
                            <div class="file-upload-container">
                                <div class="file-upload-btn">
                                    <i class="bi bi-paperclip"></i> Вибрати файли
                                    <input type="file" id="attachments" name="attachments[]" multiple
                                           accept=".jpg,.jpeg,.png,.pdf,.doc,.docx">
                                </div>
                                <div id="fileList" class="file-list">Файли не вибрано</div>
                            </div>
                            <small>
                                Ви можете прикріпити фото, скріншоти або документи, пов'язані з проблемою (макс. 5 файлів, розмір до 5MB кожен)<br>
                                Підтримуються формати: JPG, PNG, PDF, DOC, DOCX
                            </small>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn-primary">
                                <i class="bi bi-send"></i> Створити замовлення
                            </button>
                            <a href="/dah/dashboard.php" class="btn-secondary">
                                <i class="bi bi-x"></i> Скасувати
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Контактна інформація та допомога -->
                <div class="help-section">
                    <div class="help-card">
                        <h3><i class="bi bi-question-circle"></i> Потрібна допомога?</h3>
                        <p>Якщо у вас виникли питання щодо створення замовлення, ви можете зв'язатися з нашою службою підтримки:</p>
                        <ul class="contact-list">
                            <li><i class="bi bi-telephone"></i> <a href="tel:+380123456789">+38 (012) 345-67-89</a></li>
                            <li><i class="bi bi-envelope"></i> <a href="mailto:support@lagodi.com">support@lagodi.com</a></li>
                        </ul>
                        <div class="work-hours">
                            <p>Графік роботи: Пн-Пт з 9:00 до 18:00</p>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Затемнення фону при відкритті мобільного меню -->
    <div class="overlay" id="overlay"></div>

    <!-- Toast-сповіщення -->
    <div class="toast-container" id="toast-container"></div>
</div>

<script src="../../jawa/dahj/create_order.js"></script>

</body>
</html>