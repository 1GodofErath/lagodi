<?php
// Підключення файлу з базою даних
require_once __DIR__ . '/../confi/database.php';
// Підключення файлу з функціями (щоб уникнути дублювання функцій)
require_once __DIR__ . '/functions.php';

// Функція для перевірки, чи користувач авторизований
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// Функція для отримання ID поточного користувача
function getCurrentUserId() {
    return $_SESSION['user_id'] ?? null;
}

// Функція для отримання інформації про поточного користувача
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }

    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT * FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $user_id = getCurrentUserId();
    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch();

    // Не повертаємо пароль та інші чутливі дані
    if ($user) {
        unset($user['password']);
        unset($user['reset_token']);
        unset($user['verification_token']);
    }

    return $user;
}
// Функція для авторизації користувача
function loginUser($username, $password) {
    $database = new Database();
    $db = $database->getConnection();

    // Перевіряємо, чи існує користувач з таким ім'ям або email
    $query = "SELECT * FROM users WHERE username = :username OR email = :email";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $username);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Користувача з таким ім\'ям або електронною поштою не знайдено'];
    }

    // Перевіряємо, чи заблокований користувач
    if ($user['is_blocked'] == 1) {
        return ['success' => false, 'message' => 'Ваш обліковий запис заблоковано. Причина: ' . ($user['block_reason'] ?? 'Невідома причина')];
    }

    // Перевіряємо тимчасове блокування
    if ($user['blocked_until'] !== null) {
        $now = new DateTime();
        $blockedUntil = new DateTime($user['blocked_until']);

        if ($now < $blockedUntil) {
            $timeRemaining = $now->diff($blockedUntil);
            $message = 'Ваш обліковий запис тимчасово заблоковано. ';

            if ($timeRemaining->days > 0) {
                $message .= 'Розблокування через ' . $timeRemaining->days . ' днів ';
            }

            if ($timeRemaining->h > 0) {
                $message .= $timeRemaining->h . ' годин ';
            }

            if ($timeRemaining->i > 0) {
                $message .= $timeRemaining->i . ' хвилин';
            }

            return ['success' => false, 'message' => $message];
        }
    }

    // Перевіряємо пароль
    if (!password_verify($password, $user['password'])) {
        // Логуємо невдалу спробу входу
        logUserAction($user['id'], 'failed_login', 'Невдала спроба входу');

        return ['success' => false, 'message' => 'Неправильний пароль'];
    }

    // Авторизуємо користувача
    $_SESSION['user_id'] = $user['id'];
    $_SESSION['username'] = $user['username'];
    $_SESSION['role'] = $user['role'];
    $_SESSION['theme'] = $user['theme'];

    // Оновлюємо час останньої активності
    $query = "UPDATE users SET last_active = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user['id']);
    $stmt->execute();

    // Логуємо успішний вхід
    logUserAction($user['id'], 'login', 'Успішний вхід в систему');

    return [
        'success' => true,
        'message' => 'Ви успішно увійшли в систему',
        'user' => [
            'id' => $user['id'],
            'username' => $user['username'],
            'role' => $user['role'],
            'theme' => $user['theme']
        ]
    ];
}

// Функція для виходу з системи
function logoutUser() {
    if (isLoggedIn()) {
        $user_id = getCurrentUserId();

        // Логуємо вихід з системи
        logUserAction($user_id, 'logout', 'Вихід з системи');

        // Видаляємо сесію
        session_unset();
        session_destroy();

        return ['success' => true, 'message' => 'Ви успішно вийшли з системи'];
    }

    return ['success' => false, 'message' => 'Ви не авторизовані'];
}

// Функція для реєстрації нового користувача
function registerUser($username, $email, $password, $first_name = null, $last_name = null) {
    $database = new Database();
    $db = $database->getConnection();

    // Перевіряємо, чи існує користувач з таким ім'ям
    $query = "SELECT COUNT(*) as count FROM users WHERE username = :username";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':username', $username);
    $stmt->execute();

    $result = $stmt->fetch();

    if ($result['count'] > 0) {
        return ['success' => false, 'message' => 'Користувач з таким ім\'ям вже існує'];
    }

    // Перевіряємо, чи існує користувач з такою поштою
    $query = "SELECT COUNT(*) as count FROM users WHERE email = :email";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':email', $email);
    $stmt->execute();

    $result = $stmt->fetch();

    if ($result['count'] > 0) {
        return ['success' => false, 'message' => 'Користувач з такою електронною поштою вже існує'];
    }

    // Хешуємо пароль
    $hashed_password = password_hash($password, PASSWORD_DEFAULT);

    // Генеруємо токен верифікації
    $verification_token = bin2hex(random_bytes(32));

    // Створюємо нового користувача
    $query = "INSERT INTO users (username, email, password, first_name, last_name, role, verification_token, created_at) 
              VALUES (:username, :email, :password, :first_name, :last_name, 'user', :verification_token, NOW())";

    $stmt = $db->prepare($query);

    $stmt->bindParam(':username', $username);
    $stmt->bindParam(':email', $email);
    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':first_name', $first_name);
    $stmt->bindParam(':last_name', $last_name);
    $stmt->bindParam(':verification_token', $verification_token);

    if ($stmt->execute()) {
        $user_id = $db->lastInsertId();

        // Логуємо реєстрацію
        logUserAction($user_id, 'register', 'Реєстрація нового користувача');

        // Тут можна додати відправку листа з підтвердженням

        return [
            'success' => true,
            'message' => 'Реєстрація пройшла успішно. Перевірте свою пошту для підтвердження.',
            'user_id' => $user_id,
            'verification_token' => $verification_token
        ];
    }

    return ['success' => false, 'message' => 'Помилка при реєстрації користувача'];
}

// Функція для підтвердження електронної пошти
function verifyEmail($token) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id FROM users WHERE verification_token = :token AND email_verified = 0";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':token', $token);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Недійсний токен підтвердження або пошта вже підтверджена'];
    }

    $query = "UPDATE users SET email_verified = 1, verification_token = NULL WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user['id']);

    if ($stmt->execute()) {
        // Логуємо підтвердження пошти
        logUserAction($user['id'], 'verify_email', 'Підтверджено електронну пошту');

        return ['success' => true, 'message' => 'Електронну пошту успішно підтверджено'];
    }

    return ['success' => false, 'message' => 'Помилка при підтвердженні електронної пошти'];
}

// Функція для відновлення пароля (запит на відновлення)
function requestPasswordReset($email) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id, username FROM users WHERE email = :email";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':email', $email);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Користувача з такою електронною поштою не знайдено'];
    }

    // Генеруємо токен скидання пароля
    $reset_token = bin2hex(random_bytes(32));
    $expiry = new DateTime();
    $expiry->modify('+1 hour'); // Токен дійсний протягом 1 години

    $query = "UPDATE users SET reset_token = :reset_token, reset_token_expiry = :expiry WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $expiry_str = $expiry->format('Y-m-d H:i:s');

    $stmt->bindParam(':reset_token', $reset_token);
    $stmt->bindParam(':expiry', $expiry_str);
    $stmt->bindParam(':user_id', $user['id']);

    if ($stmt->execute()) {
        // Логуємо запит на відновлення пароля
        logUserAction($user['id'], 'password_reset_request', 'Запит на відновлення пароля');

        // Тут можна додати відправку листа з посиланням для скидання пароля

        return [
            'success' => true,
            'message' => 'Інструкції для відновлення пароля надіслано на вашу електронну пошту',
            'token' => $reset_token,
            'user_id' => $user['id']
        ];
    }

    return ['success' => false, 'message' => 'Помилка при запиті на відновлення пароля'];
}

// Функція для відновлення пароля (встановлення нового пароля)
function resetPassword($token, $new_password) {
    $database = new Database();
    $db = $database->getConnection();

    $query = "SELECT id FROM users WHERE reset_token = :token AND reset_token_expiry > NOW()";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':token', $token);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Недійсний або прострочений токен скидання пароля'];
    }

    // Хешуємо новий пароль
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);

    $query = "UPDATE users SET password = :password, reset_token = NULL, reset_token_expiry = NULL, 
              last_password_change = NOW() WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':password', $hashed_password);
    $stmt->bindParam(':user_id', $user['id']);

    if ($stmt->execute()) {
        // Логуємо скидання пароля
        logUserAction($user['id'], 'password_reset', 'Відновлено пароль');

        return ['success' => true, 'message' => 'Пароль успішно змінено'];
    }

    return ['success' => false, 'message' => 'Помилка при зміні пароля'];
}

// Функція для перевірки прав доступу
function checkUserRole($required_role) {
    if (!isLoggedIn()) {
        return false;
    }

    $user_role = $_SESSION['role'] ?? 'user';

    if ($required_role === 'admin') {
        return $user_role === 'admin';
    } elseif ($required_role === 'admin_or_junior') {
        return $user_role === 'admin' || $user_role === 'junior_admin';
    }

    return true;  // Для звичайних користувачів
}

// Допоміжна функція для логування дій

?>