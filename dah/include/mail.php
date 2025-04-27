<?php
// Підключаємо PHPMailer
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Підключення файлу з базою даних
require_once $_SERVER['DOCUMENT_ROOT'] . '/config/database.php';

// Функція для відправки листа
function sendEmail($to, $subject, $body, $attachments = []) {
    // Налаштування PHPMailer
    $mail = new PHPMailer(true);

    try {
        // Налаштування сервера
        $mail->isSMTP();
        $mail->Host = 'smtp.gmail.com';  // SMTP сервер, замініть на свій
        $mail->SMTPAuth = true;
        $mail->Username = 'your-email@gmail.com';  // SMTP логін
        $mail->Password = 'your-password';  // SMTP пароль
        $mail->SMTPSecure = 'tls';
        $mail->Port = 587;

        // Отримувачі
        $mail->setFrom('your-email@gmail.com', 'Lagodi Service');
        $mail->addAddress($to);

        // Вміст
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $body;

        // Додавання вкладень
        if (!empty($attachments)) {
            foreach ($attachments as $attachment) {
                $mail->addAttachment($attachment['path'], $attachment['name']);
            }
        }

        // Асинхронна відправка листа
        $mail->SMTPKeepAlive = true;

        $mail->send();
        return ['success' => true, 'message' => 'Лист успішно відправлено'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'Помилка при відправці листа: ' . $mail->ErrorInfo];
    }
}

// Функція для відправки листа підтвердження реєстрації
function sendVerificationEmail($email, $username, $token) {
    $subject = 'Підтвердження реєстрації на Lagodi Service';

    $verification_link = 'https://your-website.com/verify.php?token=' . $token;

    $body = '
    <html>
    <head>
        <title>Підтвердження реєстрації</title>
    </head>
    <body>
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
            <h2>Вітаємо на Lagodi Service!</h2>
            <p>Дякуємо за реєстрацію, ' . htmlspecialchars($username) . '!</p>
            <p>Для підтвердження вашої електронної пошти, будь ласка, перейдіть за посиланням нижче:</p>
            <p><a href="' . $verification_link . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Підтвердити пошту</a></p>
            <p>Якщо ви не реєструвалися на нашому сайті, проігноруйте цей лист.</p>
            <p>З повагою,<br>Команда Lagodi Service</p>
        </div>
    </body>
    </html>
    ';

    return sendEmail($email, $subject, $body);
}

// Функція для відправки листа відновлення пароля
function sendPasswordResetEmail($email, $username, $token) {
    $subject = 'Відновлення пароля на Lagodi Service';

    $reset_link = 'https://your-website.com/reset-password.php?token=' . $token;

    $body = '
    <html>
    <head>
        <title>Відновлення пароля</title>
    </head>
    <body>
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
            <h2>Відновлення пароля на Lagodi Service</h2>
            <p>Привіт, ' . htmlspecialchars($username) . '!</p>
            <p>Ми отримали запит на відновлення пароля для вашого облікового запису.</p>
            <p>Для встановлення нового пароля, будь ласка, перейдіть за посиланням нижче:</p>
            <p><a href="' . $reset_link . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Відновити пароль</a></p>
            <p>Посилання дійсне протягом 1 години.</p>
            <p>Якщо ви не запитували відновлення пароля, проігноруйте цей лист.</p>
            <p>З повагою,<br>Команда Lagodi Service</p>
        </div>
    </body>
    </html>
    ';

    return sendEmail($email, $subject, $body);
}

// Функція для відправки повідомлення про зміну статусу замовлення
function sendOrderStatusChangeEmail($order_id, $user_id, $new_status) {
    $database = new Database();
    $db = $database->getConnection();

    // Отримуємо інформацію про користувача
    $query = "SELECT username, email, first_name, last_name FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $user_id);
    $stmt->execute();

    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'Користувача не знайдено'];
    }

    // Отримуємо інформацію про замовлення
    $query = "SELECT service, created_at FROM orders WHERE id = :order_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();

    $order = $stmt->fetch();

    if (!$order) {
        return ['success' => false, 'message' => 'Замовлення не знайдено'];
    }

    $username = $user['first_name'] ? $user['first_name'] . ' ' . $user['last_name'] : $user['username'];
    $subject = 'Статус вашого замовлення #' . $order_id . ' змінено';

    $body = '
    <html>
    <head>
        <title>Зміна статусу замовлення</title>
    </head>
    <body>
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
            <h2>Зміна статусу замовлення</h2>
            <p>Привіт, ' . htmlspecialchars($username) . '!</p>
            <p>Статус вашого замовлення #' . $order_id . ' було змінено на <strong>' . htmlspecialchars($new_status) . '</strong>.</p>
            <p><strong>Деталі замовлення:</strong></p>
            <ul>
                <li>Сервіс: ' . htmlspecialchars($order['service']) . '</li>
                <li>Дата створення: ' . $order['created_at'] . '</li>
            </ul>
            <p>Ви можете переглянути деталі замовлення у своєму особистому кабінеті:</p>
            <p><a href="https://your-website.com/user/orders.php?id=' . $order_id . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Переглянути замовлення</a></p>
            <p>З повагою,<br>Команда Lagodi Service</p>
        </div>
    </body>
    </html>
    ';

    return sendEmail($user['email'], $subject, $body);
}

// Функція для відправки повідомлення про новий коментар
function sendNewCommentEmail($order_id, $comment_id, $user_id, $admin_id = null) {
    $database = new Database();
    $db = $database->getConnection();

    // Отримуємо інформацію про замовлення
    $query = "SELECT o.*, u.username, u.email, u.first_name, u.last_name
              FROM orders o
              LEFT JOIN users u ON o.user_id = u.id
              WHERE o.id = :order_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':order_id', $order_id);
    $stmt->execute();

    $order = $stmt->fetch();

    if (!$order) {
        return ['success' => false, 'message' => 'Замовлення не знайдено'];
    }

    // Отримуємо інформацію про коментар
    $query = "SELECT c.*, u.username, u.first_name, u.last_name, u.role
              FROM comments c
              LEFT JOIN users u ON c.user_id = u.id
              WHERE c.id = :comment_id";

    $stmt = $db->prepare($query);
    $stmt->bindParam(':comment_id', $comment_id);
    $stmt->execute();

    $comment = $stmt->fetch();

    if (!$comment) {
        return ['success' => false, 'message' => 'Коментар не знайдено'];
    }

    // Визначаємо, кому відправляти лист
    $recipient_id = ($comment['user_id'] == $order['user_id']) ? $admin_id : $order['user_id'];

    if (!$recipient_id) {
        return ['success' => false, 'message' => 'Не вказано отримувача'];
    }

    // Отримуємо інформацію про отримувача
    $query = "SELECT username, email, first_name, last_name FROM users WHERE id = :user_id";
    $stmt = $db->prepare($query);

    $stmt->bindParam(':user_id', $recipient_id);
    $stmt->execute();

    $recipient = $stmt->fetch();

    if (!$recipient) {
        return ['success' => false, 'message' => 'Отримувача не знайдено'];
    }

    $comment_author = $comment['first_name'] ? $comment['first_name'] . ' ' . $comment['last_name'] : $comment['username'];
    $recipient_name = $recipient['first_name'] ? $recipient['first_name'] . ' ' . $recipient['last_name'] : $recipient['username'];

    $subject = 'Новий коментар до замовлення #' . $order_id;

    $body = '
    <html>
    <head>
        <title>Новий коментар</title>
    </head>
    <body>
        <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
            <h2>Новий коментар до замовлення</h2>
            <p>Привіт, ' . htmlspecialchars($recipient_name) . '!</p>
            <p>' . htmlspecialchars($comment_author) . ' додав(ла) новий коментар до замовлення #' . $order_id . ':</p>
            <div style="background-color: #f2f2f2; padding: 15px; border-radius: 5px; margin: 15px 0;">
                <p style="margin: 0;">' . nl2br(htmlspecialchars($comment['content'])) . '</p>
            </div>
            <p>Ви можете переглянути замовлення та відповісти на коментар за посиланням нижче:</p>
            <p><a href="https://your-website.com/user/orders.php?id=' . $order_id . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Переглянути замовлення</a></p>
            <p>З повагою,<br>Команда Lagodi Service</p>
        </div>
    </body>
    </html>
    ';

    return sendEmail($recipient['email'], $subject, $body);
}

// Функція для відправки групових повідомлень (для адміністратора)
function sendBulkEmail($subject, $body, $user_ids = []) {
    $database = new Database();
    $db = $database->getConnection();

    // Якщо список користувачів порожній, вибираємо всіх активних користувачів
    if (empty($user_ids)) {
        $query = "SELECT id, email, username, first_name, last_name FROM users WHERE is_blocked = 0";
        $stmt = $db->prepare($query);
        $stmt->execute();

        $users = $stmt->fetchAll();
    } else {
        $placeholders = implode(',', array_fill(0, count($user_ids), '?'));
        $query = "SELECT id, email, username, first_name, last_name FROM users WHERE id IN ($placeholders) AND is_blocked = 0";
        $stmt = $db->prepare($query);

        foreach ($user_ids as $index => $id) {
            $stmt->bindValue($index + 1, $id);
        }

        $stmt->execute();
        $users = $stmt->fetchAll();
    }

    $success_count = 0;
    $failed_count = 0;
    $results = [];

    foreach ($users as $user) {
        $recipient_name = $user['first_name'] ? $user['first_name'] . ' ' . $user['last_name'] : $user['username'];

        $personalized_body = str_replace(
            ['{username}', '{first_name}', '{last_name}', '{email}'],
            [$user['username'], $user['first_name'], $user['last_name'], $user['email']],
            $body
        );

        $result = sendEmail($user['email'], $subject, $personalized_body);

        if ($result['success']) {
            $success_count++;
        } else {
            $failed_count++;
            $results[] = ['user_id' => $user['id'], 'email' => $user['email'], 'error' => $result['message']];
        }
    }

    return [
        'success' => ($failed_count == 0),
        'message' => "Відправлено $success_count листів успішно, $failed_count з помилками",
        'success_count' => $success_count,
        'failed_count' => $failed_count,
        'failed_details' => $results
    ];
}

// Функція для створення шаблону листа на основі подій
function generateEmailTemplate($type, $data) {
    $template = '';

    switch ($type) {
        case 'welcome':
            $template = '
            <html>
            <head>
                <title>Ласкаво просимо до Lagodi Service</title>
            </head>
            <body>
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                    <h2>Вітаємо в Lagodi Service!</h2>
                    <p>Привіт, ' . htmlspecialchars($data['username']) . '!</p>
                    <p>Дякуємо за реєстрацію на нашому сервісі. Ми раді, що ви з нами!</p>
                    <p>Тепер ви можете:</p>
                    <ul>
                        <li>Створювати замовлення на послуги ремонту</li>
                        <li>Відстежувати статус ваших замовлень</li>
                        <li>Спілкуватися з нашими фахівцями</li>
                        <li>Отримувати повідомлення про зміни статусу замовлень</li>
                    </ul>
                    <p>Для входу в особистий кабінет, перейдіть за посиланням:</p>
                    <p><a href="https://your-website.com/login.php" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Увійти в кабінет</a></p>
                    <p>З повагою,<br>Команда Lagodi Service</p>
                </div>
            </body>
            </html>
            ';
            break;

        case 'order_completed':
            $template = '
            <html>
            <head>
                <title>Замовлення виконано</title>
            </head>
            <body>
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                    <h2>Замовлення №' . $data['order_id'] . ' виконано</h2>
                    <p>Привіт, ' . htmlspecialchars($data['username']) . '!</p>
                    <p>Раді повідомити, що ваше замовлення №' . $data['order_id'] . ' успішно виконано.</p>
                    <p><strong>Деталі замовлення:</strong></p>
                    <ul>
                        <li>Сервіс: ' . htmlspecialchars($data['service']) . '</li>
                        <li>Дата створення: ' . $data['created_at'] . '</li>
                        <li>Дата виконання: ' . $data['completed_at'] . '</li>
                    </ul>
                    <p>Дякуємо, що скористалися нашими послугами!</p>
                    <p>Ви можете залишити відгук про нашу роботу в особистому кабінеті:</p>
                    <p><a href="https://your-website.com/user/orders.php?id=' . $data['order_id'] . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Залишити відгук</a></p>
                    <p>З повагою,<br>Команда Lagodi Service</p>
                </div>
            </body>
            </html>
            ';
            break;

        case 'order_update':
            $template = '
            <html>
            <head>
                <title>Оновлення замовлення</title>
            </head>
            <body>
                <div style="max-width: 600px; margin: 0 auto; padding: 20px; font-family: Arial, sans-serif;">
                    <h2>Оновлення статусу замовлення №' . $data['order_id'] . '</h2>
                    <p>Привіт, ' . htmlspecialchars($data['username']) . '!</p>
                    <p>Статус вашого замовлення було оновлено:</p>
                    <ul>
                        <li>Попередній статус: ' . htmlspecialchars($data['old_status']) . '</li>
                        <li>Новий статус: <strong>' . htmlspecialchars($data['new_status']) . '</strong></li>
                    </ul>
                    <p>' . nl2br(htmlspecialchars($data['comment'] ?? '')) . '</p>
                    <p>Ви можете переглянути деталі замовлення у своєму особистому кабінеті:</p>
                    <p><a href="https://your-website.com/user/orders.php?id=' . $data['order_id'] . '" style="display: inline-block; padding: 10px 20px; background-color: #4CAF50; color: white; text-decoration: none; border-radius: 5px;">Переглянути замовлення</a></p>
                    <p>З повагою,<br>Команда Lagodi Service</p>
                </div>
            </body>
            </html>
            ';
            break;
    }

    return $template;
}
?>