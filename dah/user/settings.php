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

// База даних
$database = new Database();
$db = $database->getConnection();

// Ініціалізація змінних для повідомлень
$successMessage = '';
$errorMessage = '';
$passwordSuccessMessage = '';
$passwordErrorMessage = '';
$notificationsSuccessMessage = '';
$notificationsErrorMessage = '';
$profileSuccessMessage = '';
$profileErrorMessage = '';

// Функція для санітизації рядків (заміна FILTER_SANITIZE_STRING)
function sanitizeString($str) {
    $str = strip_tags($str); // Видаляє HTML та PHP теги
    $str = trim($str); // Видаляє пробіли на початку та в кінці
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8'); // Перетворює спеціальні символи
}

// Отримуємо налаштування користувача
try {
    // Спочатку перевіряємо налаштування в таблиці users (theme)
    $userThemeQuery = "SELECT theme FROM users WHERE id = :user_id";
    $userThemeStmt = $db->prepare($userThemeQuery);
    $userThemeStmt->bindParam(':user_id', $user['id']);
    $userThemeStmt->execute();
    $userTheme = $userThemeStmt->fetchColumn();

    // Отримуємо налаштування сповіщень з таблиці user_settings
    $settingsQuery = "SELECT setting_key, setting_value FROM user_settings WHERE user_id = :user_id";
    $settingsStmt = $db->prepare($settingsQuery);
    $settingsStmt->bindParam(':user_id', $user['id']);
    $settingsStmt->execute();
    $userSettings = [];

    while ($row = $settingsStmt->fetch(PDO::FETCH_ASSOC)) {
        $userSettings[$row['setting_key']] = $row['setting_value'];
    }

    // Встановлюємо значення за замовчуванням, якщо налаштування відсутні
    $settings = [
        'notification_email' => isset($userSettings['notification_email']) ? $userSettings['notification_email'] : '1',
        'notification_site' => isset($userSettings['notification_site']) ? $userSettings['notification_site'] : '1',
        'theme_preference' => $userTheme ?: 'light'
    ];
} catch (PDOException $e) {
    $errorMessage = "Помилка при отриманні налаштувань: " . $e->getMessage();

    // Встановлюємо налаштування за замовчуванням
    $settings = [
        'notification_email' => '1',
        'notification_site' => '1',
        'theme_preference' => 'light'
    ];
}

// Встановлення теми
$currentTheme = $settings['theme_preference'] ?? (isset($_COOKIE['theme']) ? $_COOKIE['theme'] : 'light');

// Обробка завантаження фото профілю
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_avatar'])) {
    // Перевіряємо, чи файл був завантажений без помилок
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../../uploads/avatars/';

        // Створюємо директорію, якщо вона не існує
        if (!file_exists($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Отримуємо інформацію про файл
        $fileTmpPath = $_FILES['profile_picture']['tmp_name'];
        $fileName = $_FILES['profile_picture']['name'];
        $fileSize = $_FILES['profile_picture']['size'];
        $fileType = $_FILES['profile_picture']['type'];

        // Отримуємо розширення файлу
        $fileNameParts = explode('.', $fileName);
        $fileExtension = strtolower(end($fileNameParts));

        // Перевіряємо тип файлу
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif'];

        if (in_array($fileExtension, $allowedExtensions)) {
            // Генеруємо унікальне ім'я файлу
            $newFileName = 'avatar_' . $user['id'] . '_' . time() . '.' . $fileExtension;
            $uploadPath = $uploadDir . $newFileName;

            // Обмеження за розміром (5 МБ)
            if ($fileSize < 5 * 1024 * 1024) {
                if (move_uploaded_file($fileTmpPath, $uploadPath)) {
                    try {
                        // Оновлюємо шлях до фото в БД
                        $updateQuery = "UPDATE users SET profile_pic = :profile_pic WHERE id = :user_id";
                        $updateStmt = $db->prepare($updateQuery);
                        $relativePath = '/uploads/avatars/' . $newFileName; // Зберігаємо відносний шлях
                        $updateStmt->bindParam(':profile_pic', $relativePath);
                        $updateStmt->bindParam(':user_id', $user['id']);
                        $updateStmt->execute();

                        // Оновлюємо користувача в сесії
                        $user['profile_pic'] = $relativePath;
                        $_SESSION['user'] = $user;

                        $profileSuccessMessage = "Фото профілю успішно оновлено";
                    } catch (PDOException $e) {
                        $profileErrorMessage = "Помилка при оновленні фото профілю: " . $e->getMessage();
                    }
                } else {
                    $profileErrorMessage = "Не вдалося перемістити завантажений файл";
                }
            } else {
                $profileErrorMessage = "Файл занадто великий. Максимальний розмір - 5 МБ";
            }
        } else {
            $profileErrorMessage = "Недозволений тип файлу. Підтримувані типи: " . implode(', ', $allowedExtensions);
        }
    } elseif ($_FILES['profile_picture']['error'] !== UPLOAD_ERR_NO_FILE) {
        // Якщо сталася помилка при завантаженні
        switch ($_FILES['profile_picture']['error']) {
            case UPLOAD_ERR_INI_SIZE:
                $profileErrorMessage = "Файл перевищує допустимий розмір, встановлений в php.ini";
                break;
            case UPLOAD_ERR_FORM_SIZE:
                $profileErrorMessage = "Файл перевищує допустимий розмір, встановлений у формі";
                break;
            case UPLOAD_ERR_PARTIAL:
                $profileErrorMessage = "Файл був завантажений лише частково";
                break;
            case UPLOAD_ERR_NO_TMP_DIR:
                $profileErrorMessage = "Немає тимчасової директорії";
                break;
            case UPLOAD_ERR_CANT_WRITE:
                $profileErrorMessage = "Не вдалося записати файл на диск";
                break;
            case UPLOAD_ERR_EXTENSION:
                $profileErrorMessage = "Завантаження файлу зупинено розширенням PHP";
                break;
            default:
                $profileErrorMessage = "Невідома помилка при завантаженні файлу";
        }
    }
}

// Обробка оновлення профілю з можливістю зміни username
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $username = sanitizeString($_POST['username'] ?? '');
    $first_name = sanitizeString($_POST['first_name'] ?? '');
    $last_name = sanitizeString($_POST['last_name'] ?? '');
    $email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);
    $phone = sanitizeString($_POST['phone'] ?? '');

    // Валідація даних
    if (empty($username)) {
        $profileErrorMessage = "Ім'я користувача обов'язкове для заповнення";
    } elseif (empty($email)) {
        $profileErrorMessage = "Електронна пошта обов'язкова для заповнення";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $profileErrorMessage = "Некоректний формат електронної пошти";
    } else {
        try {
            // Перевірка, чи не зайнятий username іншим користувачем
            if ($username !== $user['username']) {
                $checkUsernameQuery = "SELECT id FROM users WHERE username = :username AND id != :user_id";
                $checkUsernameStmt = $db->prepare($checkUsernameQuery);
                $checkUsernameStmt->bindParam(':username', $username);
                $checkUsernameStmt->bindParam(':user_id', $user['id']);
                $checkUsernameStmt->execute();

                if ($checkUsernameStmt->rowCount() > 0) {
                    $profileErrorMessage = "Це ім'я користувача вже використовується";
                    goto skipProfileUpdate;
                }
            }

            // Перевірка, чи не зайнятий email іншим користувачем
            if ($email !== $user['email']) {
                $checkEmailQuery = "SELECT id FROM users WHERE email = :email AND id != :user_id";
                $checkEmailStmt = $db->prepare($checkEmailQuery);
                $checkEmailStmt->bindParam(':email', $email);
                $checkEmailStmt->bindParam(':user_id', $user['id']);
                $checkEmailStmt->execute();

                if ($checkEmailStmt->rowCount() > 0) {
                    $profileErrorMessage = "Ця електронна адреса вже використовується іншим користувачем";
                    goto skipProfileUpdate;
                }
            }

            // Оновлення профілю
            $updateQuery = "UPDATE users 
                        SET username = :username,
                            email = :email, 
                            phone = :phone, 
                            first_name = :first_name, 
                            last_name = :last_name
                        WHERE id = :user_id";
            $updateStmt = $db->prepare($updateQuery);
            $updateStmt->bindParam(':username', $username);
            $updateStmt->bindParam(':email', $email);
            $updateStmt->bindParam(':phone', $phone);
            $updateStmt->bindParam(':first_name', $first_name);
            $updateStmt->bindParam(':last_name', $last_name);
            $updateStmt->bindParam(':user_id', $user['id']);
            $updateStmt->execute();

            // Оновлення сесії користувача
            $user['username'] = $username;
            $user['email'] = $email;
            $user['phone'] = $phone;
            $user['first_name'] = $first_name;
            $user['last_name'] = $last_name;
            $_SESSION['user'] = $user;

            $profileSuccessMessage = "Профіль успішно оновлено";
        } catch (PDOException $e) {
            $profileErrorMessage = "Помилка при оновленні профілю: " . $e->getMessage();
        }

        skipProfileUpdate:
    }
}

// Обробка зміни пароля
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    // Валідація паролів
    if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
        $passwordErrorMessage = "Всі поля паролів повинні бути заповнені";
    } elseif ($newPassword !== $confirmPassword) {
        $passwordErrorMessage = "Новий пароль та підтвердження не співпадають";
    } elseif (strlen($newPassword) < 8) {
        $passwordErrorMessage = "Новий пароль повинен містити не менше 8 символів";
    } else {
        try {
            // Отримуємо поточний хеш пароля
            $passwordQuery = "SELECT password FROM users WHERE id = :user_id";
            $passwordStmt = $db->prepare($passwordQuery);
            $passwordStmt->bindParam(':user_id', $user['id']);
            $passwordStmt->execute();
            $currentHash = $passwordStmt->fetchColumn();

            // Перевіряємо, чи правильний поточний пароль
            if (password_verify($currentPassword, $currentHash)) {
                // Хешуємо новий пароль
                $newHash = password_hash($newPassword, PASSWORD_DEFAULT);

                // Оновлюємо пароль
                $updateQuery = "UPDATE users 
                              SET password = :password, 
                                  last_password_change = NOW() 
                              WHERE id = :user_id";
                $updateStmt = $db->prepare($updateQuery);
                $updateStmt->bindParam(':password', $newHash);
                $updateStmt->bindParam(':user_id', $user['id']);
                $updateStmt->execute();

                $passwordSuccessMessage = "Пароль успішно змінено";
            } else {
                $passwordErrorMessage = "Поточний пароль введено невірно";
            }
        } catch (PDOException $e) {
            $passwordErrorMessage = "Помилка при зміні пароля: " . $e->getMessage();
        }
    }
}

// Обробка оновлення налаштувань сповіщень
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    $notificationEmail = isset($_POST['notification_email']) ? '1' : '0';
    $notificationSite = isset($_POST['notification_site']) ? '1' : '0';

    try {
        // Оновлюємо налаштування в таблиці user_settings
        updateSetting($db, $user['id'], 'notification_email', $notificationEmail);
        updateSetting($db, $user['id'], 'notification_site', $notificationSite);

        // Оновлюємо налаштування в локальному масиві
        $settings['notification_email'] = $notificationEmail;
        $settings['notification_site'] = $notificationSite;

        $notificationsSuccessMessage = "Налаштування сповіщень успішно оновлено";
    } catch (PDOException $e) {
        $notificationsErrorMessage = "Помилка при оновленні налаштувань: " . $e->getMessage();
    }
}

// Оновлена функція для зміни теми з оновленням кукі та бази даних
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_theme'])) {
    $themePreference = $_POST['theme_preference'] ?? 'light';

    try {
        // Оновлюємо тему в таблиці users
        $updateThemeQuery = "UPDATE users SET theme = :theme WHERE id = :user_id";
        $updateThemeStmt = $db->prepare($updateThemeQuery);
        $updateThemeStmt->bindParam(':theme', $themePreference);
        $updateThemeStmt->bindParam(':user_id', $user['id']);
        $updateThemeStmt->execute();

        // Оновлюємо значення в локальному масиві
        $settings['theme_preference'] = $themePreference;
        $currentTheme = $themePreference;
        $user['theme'] = $themePreference;
        $_SESSION['user'] = $user;

        // Оновлюємо cookie для теми (на всіх сторінках)
        setcookie('theme', $themePreference, time() + (86400 * 365), "/"); // 1 рік
        $_COOKIE['theme'] = $themePreference;

        // Записуємо в localStorage через JavaScript
        echo "<script>
            localStorage.setItem('theme', '$themePreference');
            document.documentElement.setAttribute('data-theme', '$themePreference');
        </script>";

        $notificationsSuccessMessage = "Тему інтерфейсу успішно оновлено";
    } catch (PDOException $e) {
        $notificationsErrorMessage = "Помилка при оновленні теми: " . $e->getMessage();
    }
}

// Функція для оновлення або додавання налаштування
function updateSetting($db, $userId, $key, $value) {
    // Спершу перевіряємо, чи існує таке налаштування
    $checkQuery = "SELECT id FROM user_settings WHERE user_id = :user_id AND setting_key = :key";
    $checkStmt = $db->prepare($checkQuery);
    $checkStmt->bindParam(':user_id', $userId);
    $checkStmt->bindParam(':key', $key);
    $checkStmt->execute();

    if ($checkStmt->rowCount() > 0) {
        // Оновлюємо існуюче налаштування
        $updateQuery = "UPDATE user_settings 
                      SET setting_value = :value, updated_at = NOW() 
                      WHERE user_id = :user_id AND setting_key = :key";
        $updateStmt = $db->prepare($updateQuery);
        $updateStmt->bindParam(':value', $value);
        $updateStmt->bindParam(':user_id', $userId);
        $updateStmt->bindParam(':key', $key);
        $updateStmt->execute();
    } else {
        // Додаємо нове налаштування
        $insertQuery = "INSERT INTO user_settings (user_id, setting_key, setting_value, created_at) 
                       VALUES (:user_id, :key, :value, NOW())";
        $insertStmt = $db->prepare($insertQuery);
        $insertStmt->bindParam(':user_id', $userId);
        $insertStmt->bindParam(':key', $key);
        $insertStmt->bindParam(':value', $value);
        $insertStmt->execute();
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
    <title>Налаштування - Lagodi Service</title>

    <!-- Блокуємо рендеринг сторінки до встановлення теми -->
    <script>
        (function() {
            // Check localStorage first
            let theme = localStorage.getItem('theme');

            // If not in localStorage, check cookies
            if (!theme) {
                const cookies = document.cookie.split(';');
                for (let i = 0; i < cookies.length; i++) {
                    const cookie = cookies[i].trim();
                    if (cookie.startsWith('theme=')) {
                        theme = cookie.substring(6);
                        break;
                    }
                }
            }

            // If still no theme, use the system preference or default to light
            if (!theme) {
                if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
                    theme = 'dark';
                } else {
                    theme = 'light';
                }
            }

            // Apply theme to document
            document.documentElement.setAttribute('data-theme', theme);

            // Також застосовуємо тему до сайдбару при завантаженні
            document.addEventListener('DOMContentLoaded', function() {
                const sidebar = document.getElementById('sidebar');
                if (sidebar) {
                    sidebar.setAttribute('data-theme', theme);
                }

                // Встановлюємо відповідні стилі для компонентів користувача
                const userProfileWidget = document.querySelector('.user-profile-widget');
                if (userProfileWidget && theme === 'light') {
                    userProfileWidget.style.backgroundColor = '#e9ecef';

                    const userName = userProfileWidget.querySelector('.user-name');
                    if (userName) {
                        userName.style.color = '#212529';
                    }
                }
            });
        })();
    </script>

    <!-- CSS файли -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="../../style/dahm/user_dashboard.css">
    <link rel="stylesheet" href="../../style/dahm/orders.css">

    <style>
        /* Стилі для сторінки налаштувань */
        .settings-container {
            display: grid;
            grid-template-columns: 1fr;
            gap: 25px;
        }

        .settings-card {
            background-color: var(--card-bg);
            border-radius: 12px;
            border: 1px solid var(--border-color);
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
        }

        .settings-header {
            display: flex;
            align-items: center;
            margin-bottom: 20px;
            padding-bottom: 15px;
            border-bottom: 1px solid var(--border-color);
        }

        .settings-title {
            font-size: 1.3rem;
            font-weight: 600;
            color: var(--text-primary);
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .settings-title i {
            color: var(--primary-color);
        }

        .settings-form {
            margin-top: 20px;
        }

        .settings-section {
            margin-bottom: 25px;
        }

        .section-title {
            font-size: 1.1rem;
            font-weight: 600;
            margin-bottom: 15px;
            color: var(--text-primary);
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: var(--text-primary);
        }

        .form-input {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background-color: var(--input-bg, transparent);
            color: var(--text-primary);
            font-size: 0.95rem;
        }

        .form-input:focus {
            outline: none;
            border-color: var(--primary-color);
            box-shadow: 0 0 0 2px rgba(52, 152, 219, 0.1);
        }

        .form-footer {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid var(--border-color);
        }

        .form-submit {
            background-color: #1d9bf0;
            padding: 10px 20px;
            font-size: 15px;
            font-weight: bold;
            border: none;
            border-radius: 6px;
            color: white;
            cursor: pointer;
            transition: all 0.2s;
        }

        .form-submit:hover {
            background-color: #0c7abf;
            transform: translateY(-2px);
        }

        /* Блоки з налаштуваннями сповіщень */
        .toggle-section {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--border-color);
        }

        .toggle-section:last-child {
            border-bottom: none;
        }

        .toggle-label {
            font-weight: 500;
            color: var(--text-primary);
            display: flex;
            flex-direction: column;
        }

        .toggle-description {
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
            margin-top: 5px;
        }

        /* Checkbox стилізований під перемикач */
        .toggle-switch {
            position: relative;
            display: inline-block;
            width: 48px;
            height: 24px;
        }

        .toggle-switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .toggle-slider {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: #ccc;
            transition: .4s;
            border-radius: 24px;
        }

        .toggle-slider:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        .toggle-switch input:checked + .toggle-slider {
            background-color: var(--primary-color);
        }

        .toggle-switch input:focus + .toggle-slider {
            box-shadow: 0 0 1px var(--primary-color);
        }

        .toggle-switch input:checked + .toggle-slider:before {
            transform: translateX(24px);
        }

        /* Оновлені стилі для інтерфейсу вибору теми (як на скріншоті) */
        .theme-selector-container {
            display: flex;
            flex-wrap: wrap;
            gap: 20px;
            margin: 20px 0;
        }

        .theme-option-wrapper {
            position: relative;
            flex: 1;
            min-width: 200px;
        }

        .theme-option-wrapper input[type="radio"] {
            position: absolute;
            opacity: 0;
            width: 0;
            height: 0;
        }

        .theme-option-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            padding: 10px;
            border-radius: 8px;
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s ease;
        }

        .theme-option-wrapper input[type="radio"]:checked + .theme-option-card {
            border-color: #1d9bf0;
            background-color: rgba(29, 155, 240, 0.1);
        }

        .theme-preview {
            width: 100%;
            height: 140px;
            border-radius: 8px;
            overflow: hidden;
            margin-bottom: 10px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .theme-preview-header {
            height: 30px;
            border-bottom: 1px solid rgba(128, 128, 128, 0.2);
        }

        .theme-preview-content {
            height: 110px;
            padding: 10px;
        }

        .light-theme-preview {
            background-color: #ffffff;
        }

        .light-theme-preview .theme-preview-header {
            background-color: #f7f9fa;
        }

        .light-theme-preview .theme-preview-content {
            background-color: #ffffff;
        }

        .dark-theme-preview {
            background-color: #15202b;
        }

        .dark-theme-preview .theme-preview-header {
            background-color: #1a2836;
        }

        .dark-theme-preview .theme-preview-content {
            background-color: #15202b;
        }

        .theme-option-title {
            font-size: 16px;
            font-weight: 500;
            margin-top: 5px;
        }

        /* Оновлені вкладки налаштувань згідно скріншоту */
        .settings-tabs {
            display: flex;
            margin-bottom: 25px;
            border-bottom: none;
            gap: 20px;
            padding: 0 15px;
        }

        .settings-tab {
            padding: 12px 20px;
            border: none;
            background: none;
            font-weight: 500;
            color: var(--text-secondary, #888);
            cursor: pointer;
            font-size: 16px;
            position: relative;
            transition: color 0.2s;
            border-radius: 20px;
        }

        .settings-tab:hover {
            background-color: rgba(128, 128, 128, 0.1);
        }

        .settings-tab.active {
            color: var(--text-primary);
            background-color: rgba(128, 128, 128, 0.15);
        }

        .settings-tab i {
            margin-right: 8px;
        }

        /* Стилі для повідомлень */
        .alert {
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .alert-success {
            background-color: rgba(46, 204, 113, 0.1);
            border: 1px solid rgba(46, 204, 113, 0.3);
            color: #2ecc71;
        }

        .alert-danger {
            background-color: rgba(231, 76, 60, 0.1);
            border: 1px solid rgba(231, 76, 60, 0.3);
            color: #e74c3c;
        }

        /* Показник надійності пароля */
        .password-strength {
            height: 5px;
            background-color: #e0e0e0;
            border-radius: 3px;
            margin-top: 8px;
            overflow: hidden;
        }

        .password-strength-bar {
            height: 100%;
            width: 0;
            transition: width 0.3s;
        }

        .strength-weak {
            background-color: #e74c3c;
            width: 25%;
        }

        .strength-medium {
            background-color: #f39c12;
            width: 50%;
        }

        .strength-good {
            background-color: #3498db;
            width: 75%;
        }

        .strength-strong {
            background-color: #2ecc71;
            width: 100%;
        }

        .password-strength-text {
            font-size: 0.8rem;
            margin-top: 5px;
            display: flex;
            justify-content: space-between;
        }

        .password-requirements {
            margin-top: 10px;
            font-size: 0.85rem;
            color: var(--text-secondary, #888);
        }

        .password-requirement {
            display: flex;
            align-items: center;
            gap: 5px;
            margin-bottom: 3px;
        }

        .requirement-met {
            color: #2ecc71;
        }

        .requirement-not-met {
            color: #888;
        }

        .requirement-met i,
        .requirement-not-met i {
            font-size: 0.8rem;
        }

        /* Світла тема для форми */
        :root[data-theme="light"] .form-input {
            background-color: #ffffff;
            color: #333333;
            border-color: #d1d5db;
        }

        /* Темна тема для форми */
        :root[data-theme="dark"] .form-input {
            background-color: #2d3748;
            color: #e2e8f0;
            border-color: #4a5568;
        }

        /* Плавний перехід для зміни стилів при зміні теми */
        .theme-transition {
            transition: color 0.3s ease, background-color 0.3s ease, border-color 0.3s ease;
        }

        /* Секції налаштувань - вкладки */
        .settings-content {
            display: none;
        }

        .settings-content.active {
            display: block;
        }

        /* Фото профілю */
        .profile-avatar-section {
            display: flex;
            flex-direction: column;
            align-items: center;
            margin-bottom: 25px;
        }

        .profile-avatar-wrapper {
            position: relative;
            width: 150px;
            height: 150px;
            margin-bottom: 20px;
        }

        .profile-avatar {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            object-fit: cover;
            border: 3px solid var(--primary-color);
        }

        .profile-avatar-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: #7a3bdf;
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            font-weight: 600;
        }

        .profile-avatar-upload {
            position: absolute;
            bottom: 5px;
            right: 5px;
            width: 40px;
            height: 40px;
            background-color: var(--primary-color);
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            box-shadow: 0 2px 5px rgba(0, 0, 0, 0.2);
            transition: all 0.2s;
        }

        .profile-avatar-upload:hover {
            transform: scale(1.1);
            background-color: var(--primary-color-dark, #2980b9);
        }

        .profile-avatar-input {
            display: none;
        }

        .profile-name {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--text-primary);
            margin-bottom: 5px;
        }

        .profile-role {
            font-size: 0.9rem;
            color: var(--text-secondary, #888);
        }

        .avatar-form {
            text-align: center;
            margin-top: 10px;
        }

        .avatar-upload-btn {
            background-color: transparent;
            border: 1px solid var(--primary-color);
            color: var(--primary-color);
            padding: 8px 15px;
            border-radius: 6px;
            cursor: pointer;
            font-weight: 500;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }

        .avatar-upload-btn:hover {
            background-color: var(--primary-color);
            color: white;
        }

        /* Стилізований компонент користувача в лівому сайдбарі */
        .user-profile-widget {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background-color: #232323;
            border-radius: 10px;
            width: 100%;
            margin-bottom: 10px;
            transition: background-color 0.2s ease;
        }

        .user-profile-widget:hover {
            background-color: #2a2a2a;
        }

        .user-profile-widget .user-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            overflow: hidden;
            flex-shrink: 0;
        }

        .user-profile-widget .user-avatar img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .user-profile-widget .user-name {
            font-size: 16px;
            font-weight: 500;
            color: white;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex-grow: 1;
        }

        .user-avatar-placeholder {
            width: 100%;
            height: 100%;
            border-radius: 50%;
            background-color: #7a3bdf; /* Фіолетовий колір з скріншота */
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
            font-weight: 600;
        }

        /* Оновлені стилі для сайдбару з підтримкою світлої/темної теми */
        .sidebar {
            transition: background-color 0.3s ease, color 0.3s ease, border-color 0.3s ease;
        }

        :root[data-theme="light"] .sidebar {
            background-color: #f8f9fa;
            border-right: 1px solid #dee2e6;
        }

        :root[data-theme="light"] .logo {
            color: #1d9bf0;
        }

        :root[data-theme="light"] .nav-link {
            color: #4b5563;
        }

        :root[data-theme="light"] .nav-link:hover {
            background-color: #edf2f7;
            color: #1a202c;
        }

        :root[data-theme="light"] .nav-link.active {
            background-color: #e6f7ff;
            color: #1d9bf0;
        }

        :root[data-theme="light"] .nav-divider {
            background-color: #dee2e6;
        }

        :root[data-theme="light"] .sidebar-header {
            border-bottom: 1px solid #dee2e6;
        }

        :root[data-theme="light"] .user-profile-widget {
            background-color: #e9ecef;
        }

        :root[data-theme="light"] .user-profile-widget .user-name {
            color: #212529;
        }

        :root[data-theme="light"] .toggle-btn {
            color: #4b5563;
        }

        :root[data-theme="light"] .user-avatar-placeholder {
            background-color: #7a3bdf;
            color: white;
        }

        /* Темна тема для сайдбару (вже повинна бути за замовчуванням) */
        :root[data-theme="dark"] .user-profile-widget {
            background-color: #232323;
        }

        :root[data-theme="dark"] .user-profile-widget .user-name {
            color: white;
        }

        :root[data-theme="dark"] .user-avatar-placeholder {
            background-color: #7a3bdf;
            color: white;
        }

        :root[data-theme="dark"] .logo {
            color: #1d9bf0;
        }

        /* Адаптивність */
        @media (max-width: 768px) {
            .theme-options {
                justify-content: center;
            }

            .settings-tabs {
                padding-bottom: 5px;
                overflow-x: auto;
                white-space: nowrap;
            }

            .settings-tab {
                padding: 10px 15px;
                font-size: 0.9rem;
            }

            .theme-selector-container {
                flex-direction: column;
            }
        }
    </style>
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

        <!-- Новий стилізований компонент користувача -->
        <div class="user-profile-widget">
            <div class="user-avatar">
                <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                    <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю">
                <?php else: ?>
                    <div class="user-avatar-placeholder">
                        <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                    </div>
                <?php endif; ?>
            </div>
            <div class="user-name">
                <?php echo safeEcho($user['username']); ?>
            </div>
        </div>

        <nav class="sidebar-nav">
            <a href="/dah/dashboard.php" class="nav-link">
                <i class="bi bi-speedometer2"></i>
                <span>Дашборд</span>
            </a>
            <a href="/dah/user/create-order.php" class="nav-link">
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
            <a href="/user/settings.php" class="nav-link active">
                <i class="bi bi-gear"></i>
                <span>Налаштування</span>
            </a>
            <a href="/logout.php" class="nav-link logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Вихід</span>
            </a>
            <div class="nav-divider"></div>
        </nav>
    </aside>

    <!-- Основний контент -->
    <main class="main-content" id="mainContent">
        <header class="main-header">
            <button id="menuToggle" class="menu-toggle">
                <i class="bi bi-list"></i>
            </button>
            <div class="header-title">
                <h1>Налаштування</h1>
            </div>
            <div class="header-actions">
                <button id="themeSwitchBtn" class="theme-switch-btn">
                    <i class="bi <?php echo $currentTheme === 'dark' ? 'bi-sun' : 'bi-moon'; ?>"></i>
                </button>

                <div class="user-dropdown">
                    <button class="user-dropdown-btn">
                        <div class="user-avatar-small">
                            <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                                <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">
                            <?php else: ?>
                                <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                            <?php endif; ?>
                        </div>
                        <span class="user-name"><?php echo safeEcho($user['username']); ?></span>
                        <i class="bi bi-chevron-down"></i>
                    </button>
                    <div class="user-dropdown-menu">
                        <a href="/dah/user/profile.php"><i class="bi bi-person"></i> Профіль</a>
                        <a href="/user/settings.php"><i class="bi bi-gear"></i> Налаштування</a>
                        <div class="dropdown-divider"></div>
                        <a href="/logout.php"><i class="bi bi-box-arrow-right"></i> Вихід</a>
                    </div>
                </div>
            </div>
        </header>

        <!-- Загальні сповіщення -->
        <?php if ($successMessage): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?php echo $successMessage; ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?php echo $errorMessage; ?>
            </div>
        <?php endif; ?>

        <div class="content-wrapper">
            <div class="section-container">
                <div class="settings-container">
                    <!-- Вкладки налаштувань -->
                    <div class="settings-tabs">
                        <button type="button" class="settings-tab active" data-tab="profile">
                            <i class="bi bi-person"></i> Профіль
                        </button>
                        <button type="button" class="settings-tab" data-tab="security">
                            <i class="bi bi-shield-lock"></i> Безпека
                        </button>
                        <button type="button" class="settings-tab" data-tab="notifications">
                            <i class="bi bi-bell"></i> Сповіщення
                        </button>
                        <button type="button" class="settings-tab" data-tab="appearance">
                            <i class="bi bi-palette"></i> Зовнішній вигляд
                        </button>
                    </div>

                    <!-- Контент вкладки Профіль -->
                    <div class="settings-content active" id="profile-content">
                        <div class="settings-card">
                            <div class="settings-header">
                                <div class="settings-title">
                                    <i class="bi bi-person-gear"></i> Налаштування профілю
                                </div>
                            </div>

                            <!-- Сповіщення для профілю -->
                            <?php if ($profileSuccessMessage): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> <?php echo $profileSuccessMessage; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($profileErrorMessage): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle"></i> <?php echo $profileErrorMessage; ?>
                                </div>
                            <?php endif; ?>

                            <div class="profile-avatar-section">
                                <div class="profile-avatar-wrapper">
                                    <?php if(isset($user['profile_pic']) && !empty($user['profile_pic'])): ?>
                                        <img src="<?php echo safeEcho($user['profile_pic']); ?>" alt="Фото профілю" class="profile-avatar">
                                    <?php else: ?>
                                        <div class="profile-avatar-placeholder">
                                            <?php echo strtoupper(substr($user['username'] ?? '', 0, 1)); ?>
                                        </div>
                                    <?php endif; ?>
                                    <label for="profile_picture" class="profile-avatar-upload">
                                        <i class="bi bi-camera"></i>
                                    </label>
                                </div>
                                <div class="profile-name"><?php echo safeEcho($user['username']); ?></div>
                                <div class="profile-role">
                                    <?php
                                    $roleName = "Користувач";
                                    if (isset($user['role'])) {
                                        if ($user['role'] === 'admin') {
                                            $roleName = "Адміністратор";
                                        } elseif ($user['role'] === 'junior_admin') {
                                            $roleName = "Молодший адміністратор";
                                        }
                                    }
                                    echo $roleName;
                                    ?>
                                </div>
                                <form action="settings.php" method="post" enctype="multipart/form-data" class="avatar-form">
                                    <input type="file" id="profile_picture" name="profile_picture" accept="image/*" class="profile-avatar-input">
                                    <button type="submit" name="update_avatar" class="avatar-upload-btn" id="upload-avatar-btn" style="display: none;">
                                        <i class="bi bi-cloud-upload"></i> Завантажити фото
                                    </button>
                                </form>
                            </div>

                            <div class="settings-form">
                                <form method="post" action="settings.php">
                                    <div class="form-group">
                                        <label for="username" class="form-label">Ім'я користувача</label>
                                        <input type="text" id="username" name="username" class="form-input" value="<?php echo safeEcho($user['username']); ?>" required>
                                        <small style="display: block; margin-top: 5px; color: var(--text-secondary);">Це ім'я буде відображатися на сайті</small>
                                    </div>

                                    <div class="form-group">
                                        <label for="first_name" class="form-label">Ім'я</label>
                                        <input type="text" id="first_name" name="first_name" class="form-input" value="<?php echo safeEcho($user['first_name'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label for="last_name" class="form-label">Прізвище</label>
                                        <input type="text" id="last_name" name="last_name" class="form-input" value="<?php echo safeEcho($user['last_name'] ?? ''); ?>">
                                    </div>

                                    <div class="form-group">
                                        <label for="email" class="form-label">Email</label>
                                        <input type="email" id="email" name="email" class="form-input" value="<?php echo safeEcho($user['email']); ?>" required>
                                    </div>

                                    <div class="form-group">
                                        <label for="phone" class="form-label">Телефон</label>
                                        <input type="tel" id="phone" name="phone" class="form-input" value="<?php echo safeEcho($user['phone'] ?? ''); ?>">
                                    </div>

                                    <div class="form-footer">
                                        <button type="submit" name="update_profile" class="form-submit">
                                            <i class="bi bi-check-lg"></i> Зберегти зміни
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Контент вкладки Безпека -->
                    <div class="settings-content" id="security-content">
                        <div class="settings-card">
                            <div class="settings-header">
                                <div class="settings-title">
                                    <i class="bi bi-shield-lock"></i> Безпека
                                </div>
                            </div>

                            <!-- Сповіщення для зміни пароля -->
                            <?php if ($passwordSuccessMessage): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> <?php echo $passwordSuccessMessage; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($passwordErrorMessage): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle"></i> <?php echo $passwordErrorMessage; ?>
                                </div>
                            <?php endif; ?>

                            <div class="settings-form">
                                <form method="post" action="settings.php">
                                    <div class="settings-section">
                                        <div class="section-title">Зміна пароля</div>

                                        <div class="form-group">
                                            <label for="current_password" class="form-label">Поточний пароль</label>
                                            <input type="password" id="current_password" name="current_password" class="form-input" required>
                                        </div>

                                        <div class="form-group">
                                            <label for="new_password" class="form-label">Новий пароль</label>
                                            <input type="password" id="new_password" name="new_password" class="form-input" required>
                                            <div class="password-strength">
                                                <div class="password-strength-bar" id="passwordStrengthBar"></div>
                                            </div>
                                            <div class="password-strength-text">
                                                <span id="passwordStrengthText">Введіть новий пароль</span>
                                            </div>
                                            <div class="password-requirements">
                                                <div class="password-requirement" id="lengthRequirement">
                                                    <i class="bi bi-circle"></i> Мінімум 8 символів
                                                </div>
                                                <div class="password-requirement" id="caseRequirement">
                                                    <i class="bi bi-circle"></i> Літери різного регістру (A-Z, a-z)
                                                </div>
                                                <div class="password-requirement" id="numberRequirement">
                                                    <i class="bi bi-circle"></i> Містить цифри (0-9)
                                                </div>
                                                <div class="password-requirement" id="specialRequirement">
                                                    <i class="bi bi-circle"></i> Спеціальні символи (@#$%^&*!)
                                                </div>
                                            </div>
                                        </div>

                                        <div class="form-group">
                                            <label for="confirm_password" class="form-label">Підтвердження нового пароля</label>
                                            <input type="password" id="confirm_password" name="confirm_password" class="form-input" required>
                                            <div id="passwordMatchMessage" class="password-strength-text"></div>
                                        </div>
                                    </div>

                                    <div class="form-footer">
                                        <button type="submit" name="change_password" class="form-submit">
                                            <i class="bi bi-check-lg"></i> Зберегти новий пароль
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Контент вкладки Сповіщення -->
                    <div class="settings-content" id="notifications-content">
                        <div class="settings-card">
                            <div class="settings-header">
                                <div class="settings-title">
                                    <i class="bi bi-bell"></i> Налаштування сповіщень
                                </div>
                            </div>

                            <!-- Сповіщення для налаштувань сповіщень -->
                            <?php if ($notificationsSuccessMessage): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> <?php echo $notificationsSuccessMessage; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($notificationsErrorMessage): ?>
                                <div class="alert alert-danger">
                                    <i class="bi bi-exclamation-triangle"></i> <?php echo $notificationsErrorMessage; ?>
                                </div>
                            <?php endif; ?>

                            <div class="settings-form">
                                <form method="post" action="settings.php">
                                    <div class="settings-section">
                                        <div class="toggle-section">
                                            <div class="toggle-label">
                                                Сповіщення електронною поштою
                                                <span class="toggle-description">Отримувати сповіщення про нові коментарі та оновлення замовлень на email</span>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="notification_email" <?php echo (isset($settings['notification_email']) && $settings['notification_email'] == '1') ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>

                                        <div class="toggle-section">
                                            <div class="toggle-label">
                                                Сповіщення на сайті
                                                <span class="toggle-description">Показувати сповіщення про нові коментарі та оновлення замовлень на сайті</span>
                                            </div>
                                            <label class="toggle-switch">
                                                <input type="checkbox" name="notification_site" <?php echo (isset($settings['notification_site']) && $settings['notification_site'] == '1') ? 'checked' : ''; ?>>
                                                <span class="toggle-slider"></span>
                                            </label>
                                        </div>
                                    </div>

                                    <div class="form-footer">
                                        <button type="submit" name="update_notifications" class="form-submit">
                                            <i class="bi bi-check-lg"></i> Зберегти налаштування
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>

                    <!-- Контент вкладки Зовнішній вигляд згідно скріншоту -->
                    <div class="settings-content" id="appearance-content">
                        <div class="settings-card">
                            <div class="settings-header">
                                <div class="settings-title">
                                    <i class="bi bi-palette"></i> Налаштування зовнішнього вигляду
                                </div>
                            </div>

                            <!-- Сповіщення для налаштувань вигляду -->
                            <?php if (isset($_POST['update_theme']) && $notificationsSuccessMessage): ?>
                                <div class="alert alert-success">
                                    <i class="bi bi-check-circle"></i> <?php echo $notificationsSuccessMessage; ?>
                                </div>
                            <?php endif; ?>

                            <div class="settings-form">
                                <form method="post" action="settings.php">
                                    <div class="settings-section">
                                        <h2 class="section-title">Тема інтерфейсу</h2>
                                        <p>Виберіть переважну тему інтерфейсу для вашого облікового запису.</p>

                                        <div class="theme-selector-container">
                                            <div class="theme-option-wrapper">
                                                <input type="radio" id="theme-light" name="theme_preference" value="light" <?php echo (isset($settings['theme_preference']) && $settings['theme_preference'] == 'light') ? 'checked' : ''; ?>>
                                                <label for="theme-light" class="theme-option-card">
                                                    <div class="theme-preview light-theme-preview">
                                                        <div class="theme-preview-header"></div>
                                                        <div class="theme-preview-content"></div>
                                                    </div>
                                                    <span class="theme-option-title">Світла</span>
                                                </label>
                                            </div>

                                            <div class="theme-option-wrapper">
                                                <input type="radio" id="theme-dark" name="theme_preference" value="dark" <?php echo (isset($settings['theme_preference']) && $settings['theme_preference'] == 'dark') ? 'checked' : ''; ?>>
                                                <label for="theme-dark" class="theme-option-card">
                                                    <div class="theme-preview dark-theme-preview">
                                                        <div class="theme-preview-header"></div>
                                                        <div class="theme-preview-content"></div>
                                                    </div>
                                                    <span class="theme-option-title">Темна</span>
                                                </label>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="form-footer">
                                        <button type="submit" name="update_theme" class="form-submit">
                                            <i class="bi bi-check-lg"></i> Зберегти налаштування
                                        </button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Затемнення фону при відкритті мобільного меню -->
    <div class="overlay" id="overlay"></div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const sidebar = document.getElementById('sidebar');
        const mainContent = document.getElementById('mainContent');
        const mainWrapper = document.getElementById('mainWrapper');
        const menuToggle = document.getElementById('menuToggle');
        const sidebarToggle = document.getElementById('sidebarToggle');
        const overlay = document.getElementById('overlay');
        const themeSwitchBtn = document.getElementById('themeSwitchBtn');

        // Перевіряємо, чи є збережений стан сайдбара
        const sidebarCollapsed = localStorage.getItem('sidebarCollapsed') === 'true';

        // Функція для скривання/відображення сайдбару
        function toggleSidebar(collapse) {
            if (mainWrapper) {
                if (collapse) {
                    mainWrapper.classList.add('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', 'true');
                    if (sidebarToggle) sidebarToggle.innerHTML = '<i class="bi bi-arrow-right"></i>';
                } else {
                    mainWrapper.classList.remove('sidebar-collapsed');
                    localStorage.setItem('sidebarCollapsed', 'false');
                    if (sidebarToggle) sidebarToggle.innerHTML = '<i class="bi bi-arrow-left"></i>';
                }
            }
        }

        // Встановлюємо початковий стан сайдбару
        toggleSidebar(sidebarCollapsed);

        // Обробник для кнопки згортання сайдбару
        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                const isCollapsed = mainWrapper && mainWrapper.classList.contains('sidebar-collapsed');
                toggleSidebar(!isCollapsed);
            });
        }

        // На мобільних пристроях сайдбар виїжджає
        if (menuToggle && sidebar && overlay) {
            menuToggle.addEventListener('click', function() {
                sidebar.classList.toggle('mobile-active');
                overlay.classList.toggle('active');
                document.body.classList.toggle('no-scroll');
            });

            // Клік по затемненню закриває меню
            overlay.addEventListener('click', function() {
                sidebar.classList.remove('mobile-active');
                overlay.classList.remove('active');
                document.body.classList.remove('no-scroll');
            });
        }

        // Випадаюче меню користувача
        const userDropdownBtn = document.querySelector('.user-dropdown-btn');
        const userDropdownMenu = document.querySelector('.user-dropdown-menu');

        if (userDropdownBtn && userDropdownMenu) {
            userDropdownBtn.addEventListener('click', function(event) {
                event.stopPropagation();
                userDropdownMenu.classList.toggle('show');
            });

            // Клік поза меню закриває їх
            document.addEventListener('click', function(event) {
                if (userDropdownMenu.classList.contains('show') &&
                    !userDropdownBtn.contains(event.target) &&
                    !userDropdownMenu.contains(event.target)) {
                    userDropdownMenu.classList.remove('show');
                }
            });
        }

        // Функція для застосування теми до всіх елементів сайту
        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            localStorage.setItem('theme', theme);

            // Встановлюємо куки, щоб тема зберігалась після перезавантаження
            let date = new Date();
            date.setTime(date.getTime() + (365 * 24 * 60 * 60 * 1000)); // 1 рік
            let expires = "expires=" + date.toUTCString();
            document.cookie = "theme=" + theme + ";" + expires + ";path=/";

            // Оновлюємо вигляд кнопки перемикання теми
            const themeSwitchBtn = document.getElementById('themeSwitchBtn');
            if (themeSwitchBtn) {
                const icon = themeSwitchBtn.querySelector('i');
                if (icon) {
                    icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
                }
            }

            // Застосовуємо відповідні стилі до сайдбару
            const sidebar = document.getElementById('sidebar');
            if (sidebar) {
                sidebar.setAttribute('data-theme', theme);
            }

            // Застосовуємо стилі до компонента користувача
            const userProfileWidget = document.querySelector('.user-profile-widget');
            if (userProfileWidget) {
                if (theme === 'light') {
                    userProfileWidget.style.backgroundColor = '#e9ecef';
                } else {
                    userProfileWidget.style.backgroundColor = '#232323';
                }
            }

            // Оновлюємо колір імені користувача
            const userName = document.querySelector('.user-profile-widget .user-name');
            if (userName) {
                userName.style.color = theme === 'light' ? '#212529' : 'white';
            }
        }

        // Функція для перемикання теми з плавним переходом
        function toggleTheme() {
            // Отримуємо поточну тему
            const htmlElement = document.documentElement;
            const currentTheme = htmlElement.getAttribute('data-theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            // Застосовуємо нову тему через нашу функцію
            applyTheme(newTheme);

            // Додаємо клас для плавного переходу
            document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';

            // Зберігаємо поточний скрол
            const scrollPosition = window.pageYOffset;

            // Невелика затримка для анімації
            setTimeout(() => {
                // Після переходу - видаляємо стильове правило переходу
                document.body.style.transition = '';

                // Відновлюємо позицію скролу
                window.scrollTo(0, scrollPosition);
            }, 300);
        }

        // Додаємо обробники подій для перемикання теми
        if (themeSwitchBtn) {
            themeSwitchBtn.addEventListener('click', function(e) {
                e.preventDefault();
                toggleTheme();
            });
        }

        // Обробка вкладок
        const tabs = document.querySelectorAll('.settings-tab');

        tabs.forEach(tab => {
            tab.addEventListener('click', function() {
                // Видаляємо клас активності з усіх вкладок
                tabs.forEach(t => t.classList.remove('active'));

                // Додаємо клас активності поточній вкладці
                this.classList.add('active');

                // Отримуємо ідентифікатор контенту для цієї вкладки
                const tabId = this.getAttribute('data-tab');

                // Приховуємо всі контенти
                document.querySelectorAll('.settings-content').forEach(content => {
                    content.classList.remove('active');
                });

                // Показуємо потрібний контент
                document.getElementById(`${tabId}-content`).classList.add('active');
            });
        });

        // Зміна розміру екрану - для адаптивного дизайну
        window.addEventListener('resize', function() {
            if (window.innerWidth >= 992 && sidebar) {
                if (sidebar.classList.contains('mobile-active')) {
                    sidebar.classList.remove('mobile-active');
                    overlay.classList.remove('active');
                    document.body.classList.remove('no-scroll');
                }
            }
        });

        // Автоматичне закриття сповіщень
        const alerts = document.querySelectorAll('.alert');
        if (alerts.length > 0) {
            setTimeout(function() {
                alerts.forEach(alert => {
                    alert.style.opacity = '0';
                    setTimeout(() => {
                        alert.style.display = 'none';
                    }, 300);
                });
            }, 5000);
        }

        // Показник надійності пароля
        const passwordInput = document.getElementById('new_password');
        const confirmPasswordInput = document.getElementById('confirm_password');
        const passwordStrengthBar = document.getElementById('passwordStrengthBar');
        const passwordStrengthText = document.getElementById('passwordStrengthText');
        const passwordMatchMessage = document.getElementById('passwordMatchMessage');

        // Вимоги до пароля
        const lengthRequirement = document.getElementById('lengthRequirement');
        const caseRequirement = document.getElementById('caseRequirement');
        const numberRequirement = document.getElementById('numberRequirement');
        const specialRequirement = document.getElementById('specialRequirement');

        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const password = this.value;

                // Оцінка складності пароля
                let strength = 0;
                let feedback = "";

                // Перевірка на довжину (мінімум 8 символів)
                if (password.length >= 8) {
                    strength += 25;
                    lengthRequirement.innerHTML = '<i class="bi bi-check-circle-fill"></i> Мінімум 8 символів';
                    lengthRequirement.className = 'password-requirement requirement-met';
                } else {
                    lengthRequirement.innerHTML = '<i class="bi bi-circle"></i> Мінімум 8 символів';
                    lengthRequirement.className = 'password-requirement requirement-not-met';
                }

                // Перевірка на наявність великих і малих літер
                if (/[a-z]/.test(password) && /[A-Z]/.test(password)) {
                    strength += 25;
                    caseRequirement.innerHTML = '<i class="bi bi-check-circle-fill"></i> Літери різного регістру (A-Z, a-z)';
                    caseRequirement.className = 'password-requirement requirement-met';
                } else {
                    caseRequirement.innerHTML = '<i class="bi bi-circle"></i> Літери різного регістру (A-Z, a-z)';
                    caseRequirement.className = 'password-requirement requirement-not-met';
                }

                // Перевірка на наявність цифр
                if (/[0-9]/.test(password)) {
                    strength += 25;
                    numberRequirement.innerHTML = '<i class="bi bi-check-circle-fill"></i> Містить цифри (0-9)';
                    numberRequirement.className = 'password-requirement requirement-met';
                } else {
                    numberRequirement.innerHTML = '<i class="bi bi-circle"></i> Містить цифри (0-9)';
                    numberRequirement.className = 'password-requirement requirement-not-met';
                }

                // Перевірка на наявність спеціальних символів
                if (/[^a-zA-Z0-9]/.test(password)) {
                    strength += 25;
                    specialRequirement.innerHTML = '<i class="bi bi-check-circle-fill"></i> Спеціальні символи (@#$%^&*!)';
                    specialRequirement.className = 'password-requirement requirement-met';
                } else {
                    specialRequirement.innerHTML = '<i class="bi bi-circle"></i> Спеціальні символи (@#$%^&*!)';
                    specialRequirement.className = 'password-requirement requirement-not-met';
                }

                // Оновлюємо показник надійності
                passwordStrengthBar.style.width = strength + '%';

                // Оновлюємо клас залежно від надійності
                if (strength <= 25) {
                    passwordStrengthBar.className = 'password-strength-bar strength-weak';
                    feedback = "Слабкий пароль";
                } else if (strength <= 50) {
                    passwordStrengthBar.className = 'password-strength-bar strength-medium';
                    feedback = "Середня надійність";
                } else if (strength <= 75) {
                    passwordStrengthBar.className = 'password-strength-bar strength-good';
                    feedback = "Хороша надійність";
                } else {
                    passwordStrengthBar.className = 'password-strength-bar strength-strong';
                    feedback = "Сильний пароль";
                }

                passwordStrengthText.textContent = feedback;

                // Перевіряємо збіг паролів, якщо поле підтвердження не порожнє
                if (confirmPasswordInput.value) {
                    checkPasswordsMatch();
                }
            });
        }

        // Перевірка збігу паролів
        if (confirmPasswordInput) {
            confirmPasswordInput.addEventListener('input', checkPasswordsMatch);
        }

        function checkPasswordsMatch() {
            if (passwordInput.value === confirmPasswordInput.value) {
                passwordMatchMessage.textContent = "Паролі співпадають";
                passwordMatchMessage.style.color = "#2ecc71";
            } else {
                passwordMatchMessage.textContent = "Паролі не співпадають";
                passwordMatchMessage.style.color = "#e74c3c";
            }
        }

        // Форматування телефону
        const phoneInput = document.getElementById('phone');

        if (phoneInput) {
            phoneInput.addEventListener('input', function(e) {
                let value = e.target.value.replace(/\D/g, '');

                if (value.length > 0) {
                    if (value[0] === '3' && value[1] === '8' && value[2] === '0') {
                        // Формат: +380 XX XXX XX XX
                        if (value.length > 3) {
                            value = '+380 ' + value.substring(3, 5) +
                                (value.length > 5 ? ' ' + value.substring(5, 8) : '') +
                                (value.length > 8 ? ' ' + value.substring(8, 10) : '') +
                                (value.length > 10 ? ' ' + value.substring(10, 12) : '');
                        } else {
                            value = '+380';
                        }
                    } else if (value[0] !== '3') {
                        // Додаємо український код, якщо введено без нього
                        value = '+380 ' + value.substring(0, 2) +
                            (value.length > 2 ? ' ' + value.substring(2, 5) : '') +
                            (value.length > 5 ? ' ' + value.substring(5, 7) : '') +
                            (value.length > 7 ? ' ' + value.substring(7, 9) : '');
                    }
                }

                e.target.value = value;
            });
        }

        // Обробка завантаження фото профілю
        const profilePictureInput = document.getElementById('profile_picture');
        const uploadAvatarBtn = document.getElementById('upload-avatar-btn');

        if (profilePictureInput && uploadAvatarBtn) {
            profilePictureInput.addEventListener('change', function() {
                if (this.files && this.files[0]) {
                    // Показуємо кнопку завантаження, якщо вибрано файл
                    uploadAvatarBtn.style.display = 'inline-flex';

                    // Показуємо попередній перегляд фото
                    const reader = new FileReader();

                    reader.onload = function(e) {
                        // Знаходимо елемент для відображення фото
                        const profileAvatar = document.querySelector('.profile-avatar');
                        const profileAvatarPlaceholder = document.querySelector('.profile-avatar-placeholder');

                        if (profileAvatar) {
                            // Оновлюємо src атрибут, якщо елемент img вже існує
                            profileAvatar.src = e.target.result;
                        } else if (profileAvatarPlaceholder) {
                            // Створюємо новий елемент img і замінюємо placeholder
                            const img = document.createElement('img');
                            img.src = e.target.result;
                            img.className = 'profile-avatar';
                            img.alt = 'Фото профілю';

                            profileAvatarPlaceholder.parentNode.replaceChild(img, profileAvatarPlaceholder);
                        }
                    }

                    reader.readAsDataURL(this.files[0]);
                } else {
                    // Приховуємо кнопку, якщо файл не вибрано
                    uploadAvatarBtn.style.display = 'none';
                }
            });
        }

        // Слухачі подій для радіокнопок вибору теми
        const themeRadios = document.querySelectorAll('input[name="theme_preference"]');
        themeRadios.forEach(radio => {
            radio.addEventListener('change', function() {
                if (this.checked) {
                    applyTheme(this.value);
                }
            });
        });

        // Застосовуємо поточну тему при завантаженні сторінки
        const currentTheme = document.documentElement.getAttribute('data-theme');
        if (currentTheme) {
            applyTheme(currentTheme);
        }
    });
</script>
</body>
</html>