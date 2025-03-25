<?php
session_start();

ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Include database connection
require __DIR__ . '/../db.php';

if ($conn->connect_error) {
    die("Помилка підключення до БД: " . $conn->connect_error);
}

if (!isset($_SESSION['role'])) {
    header("Location: /../login.php");
    exit();
}

if (!in_array($_SESSION['role'], ['admin', 'junior_admin'])) {
    die("Доступ заборонено");
}

// Get parameters
$statusFilter = isset($_GET['статус']) ? trim($_GET['статус']) : 'всі';
$activeSection = $_GET['section'] ?? 'orders';
$userSearch = $_GET['user_search'] ?? '';
$logPage = isset($_GET['log_page']) ? (int)$_GET['log_page'] : 1;
$logUser = $_GET['log_user'] ?? '';
$logDate = $_GET['log_date'] ?? '';
$logAction = $_GET['log_action'] ?? '';

// Retrieve orders with an optional status filter
try {
    $statusCondition = ($statusFilter !== 'всі') ? " AND o.status = ?" : "";
    $sql = "SELECT 
                o.id,
                u.username,
                o.service,
                o.details,
                o.status,
                o.user_id,
                o.created_at
            FROM orders o
            JOIN users u ON o.user_id = u.id
            WHERE 1=1 $statusCondition
            ORDER BY o.id DESC";
    $stmt = $conn->prepare($sql);
    if ($statusFilter !== 'всі') {
        $stmt->bind_param("s", $statusFilter);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $orders = $result->fetch_all(MYSQLI_ASSOC);
} catch (Exception $e) {
    die("Помилка: " . $e->getMessage());
}

// Retrieve comments for each order
$comments = [];
foreach ($orders as $order) {
    try {
        $stmt = $conn->prepare("
            SELECT c.*, u.username, u.role 
            FROM comments c
            JOIN users u ON c.user_id = u.id 
            WHERE c.order_id = ?
            ORDER BY c.created_at DESC
        ");
        $stmt->bind_param("i", $order['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        $comments[$order['id']] = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        die("Помилка коментарів: " . $e->getMessage());
    }
}

// Retrieve users (only for admin)
$users = [];
if ($_SESSION['role'] === 'admin') {
    try {
        $search = "%{$userSearch}%";
        $sql = "SELECT id, username, role, blocked_until, block_reason
                FROM users 
                WHERE username LIKE ?
                ORDER BY FIELD(role, 'admin', 'junior_admin') DESC, username ASC";
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("s", $search);
        $stmt->execute();
        $result = $stmt->get_result();
        $users = $result->fetch_all(MYSQLI_ASSOC);
    } catch (Exception $e) {
        die("Помилка отримання користувачів: " . $e->getMessage());
    }
}

// Retrieve logs (only for admin)
$logs = [];
$totalPages = 1;
if ($_SESSION['role'] === 'admin') {
    try {
        $limit = 10;
        $offset = ($logPage - 1) * $limit;
        $userFilter = "%{$logUser}%";
        $actionFilter = "%{$logAction}%";
        $sql = "SELECT SQL_CALC_FOUND_ROWS l.*, u.username, u.role 
                FROM logs l
                JOIN users u ON l.user_id = u.id
                WHERE u.username LIKE ?
                AND (DATE(l.created_at) = ? OR ? = '')
                AND l.action LIKE ?
                ORDER BY l.created_at DESC
                LIMIT ? OFFSET ?";
        $stmt = $conn->prepare($sql);
        $dateParam = $logDate ?: '';
        $stmt->bind_param("ssssii",
            $userFilter,
            $dateParam,
            $dateParam,
            $actionFilter,
            $limit,
            $offset
        );
        $stmt->execute();
        $result = $stmt->get_result();
        $logs = $result->fetch_all(MYSQLI_ASSOC);
        $totalResult = $conn->query("SELECT FOUND_ROWS() AS total");
        $totalLogs = $totalResult->fetch_assoc()['total'];
        $totalPages = ceil($totalLogs / $limit);
    } catch (Exception $e) {
        die("Помилка отримання логів: " . $e->getMessage());
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>
<!DOCTYPE html>
<html lang="uk">
<head>
    <meta charset="UTF-8">
    <!-- 🟡 Додаємо viewport для адаптації -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Адмін-панель</title>
    <link rel="stylesheet" href="/../style/dash.css">
</head>
<body>
<div class="container">
    <div class="navbar">
        <div class="welcome-group">
            <div class="welcome-avatar"><?= substr(htmlspecialchars($_SESSION['username']), 0, 2) ?></div>
            <div class="welcome-text">
                <h1 class="welcome-heading">
                    Вітаємо, <span><?= htmlspecialchars($_SESSION['username']) ?></span>
                </h1>
                <div class="role-indicator">
                    <?php if ($_SESSION['role'] === 'admin'): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M20.995 4.824A1 1 0 0020 4h-16a1 1 0 00-.995.824L2 8v1a3 3 0 003 3c1.306 0 2.418-.835 2.83-2h8.34c.412 1.165 1.524 2 2.83 2a3 3 0 003-3V8l-1.005-3.176zM5 10a1 1 0 110-2 1 1 0 010 2zm14 0a1 1 0 110-2 1 1 0 010 2z"/>
                        </svg>
                        Адміністратор
                    <?php elseif ($_SESSION['role'] === 'junior_admin'): ?>
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 2C6.486 2 2 6.486 2 12s4.486 10 10 10 10-4.486 10-10S17.514 2 12 2zm0 18c-4.411 0-8-3.589-8-8s3.589-8 8-8 8 3.589 8 8-3.589 8-8 8z"/>
                            <path d="M13 7h-2v5.414l3.293 3.293 1.414-1.414L13 11.586z"/>
                        </svg>
                        Молодший Адміністратор
                    <?php endif; ?>
                    <div class="mobile-nav" style="display: none;">
                        <a href="?section=orders">Замовлення</a>
                        <?php if ($_SESSION['role'] === 'admin'): ?>
                            <a href="?section=users">Користувачі</a>
                            <a href="?section=logs">Логи</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="system-info">
            <div class="time-display">
                <div class="time-label">Поточний час (UTC)</div>
                <div class="time-value">2025-03-04 19:29:28</div>
            </div>
            <a href="/../logout.php" class="logout-btn">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M16 13v-2H7V8l-5 4 5 4v-3z"/>
                    <path d="M20 3h-9c-1.103 0-2 .897-2 2v4h2V5h9v14h-9v-4H9v4c0 1.103.897 2 2 2h9c1.103 0 2-.897 2-2V5c0-1.103-.897-2-2-2z"/>
                </svg>
                Вийти
            </a>
        </div>
    </div>

    <!-- Admin Sections -->
    <div class="admin-sections">

        <a href="?section=orders" data-section="orders" class="<?= $activeSection === 'orders' ? 'active' : '' ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                <path d="M19 3H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm0 16H5V5h14v14z"/>
                <path d="M7 7h10v2H7zm0 4h10v2H7zm0 4h7v2H7z"/>
            </svg>

            Замовлення
        </a>
        <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="?section=users" data-section="users" class="<?= $activeSection === 'users' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                </svg>
                Користувачі
            </a>
            <a href="?section=logs" data-section="logs" class="<?= $activeSection === 'logs' ? 'active' : '' ?>">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                    <path d="M19 3h-4.18C14.4 1.84 13.3 1 12 1c-1.3 0-2.4.84-2.82 2H5c-1.1 0-2 .9-2 2v14c0 1.1.9 2 2 2h14c1.1 0 2-.9 2-2V5c0-1.1-.9-2-2-2zm-7 0c.55 0 1 .45 1 1s-.45 1-1 1-1-.45-1-1 .45-1 1-1zm2 14H7v-2h7v2zm3-4H7v-2h10v2zm0-4H7V7h10v2z"/>
                </svg>
                Логи
            </a>
        <?php endif; ?>
    </div>

    <!-- Orders Section -->
    <section id="orders" style="display: <?= $activeSection === 'orders' ? 'block' : 'none' ?>;">


        <h2>Замовлення</h2>
        <div class="status-filter">
            <a href="?section=orders&статус=всі" class="<?= $statusFilter === 'всі' ? 'active' : '' ?>">Всі</a>
            <a href="?section=orders&статус=Новий" class="<?= $statusFilter === 'Новий' ? 'active' : '' ?>">Нові</a>
            <a href="?section=orders&статус=Очікується поставки товару" class="<?= $statusFilter === 'Очікується поставки товару' ? 'active' : '' ?>">Очікується поставки товару</a>
            <a href="?section=orders&статус=В роботі" class="<?= $statusFilter === 'В роботі' ? 'active' : '' ?>">В роботі</a>
            <a href="?section=orders&статус=Завершено" class="<?= $statusFilter === 'Завершено' ? 'active' : '' ?>">Завершено</a>
            <?php if (in_array($_SESSION['role'], ['admin', 'junior_admin'])): ?>
                <a href="?section=orders&статус=Неможливо виконати" class="<?= $statusFilter === 'Неможливо виконати' ? 'active' : '' ?>">Неможливо виконати</a>
            <?php endif; ?>
        </div>
        <div class="orders">
            <?php foreach ($orders as $order): ?>
                <?php
                if ($_SESSION['role'] === 'admin') {
                    $disabled = ($order['status'] === 'Завершено' || $order['status'] === 'Неможливо виконати');
                } else {
                    $disabled = false;
                }
                ?>
                <div class="order-card <?= $disabled ? 'completed-order' : '' ?>" data-status="<?= htmlspecialchars($order['status']) ?>">
                    <h3>Замовлення #<?= htmlspecialchars($order['id']) ?></h3>
                    <div class="customer-info">
                        <p><strong>Клієнт:</strong> <?= htmlspecialchars($order['username']) ?></p>
                        <p><strong>Послуга:</strong> <?= htmlspecialchars($order['service']) ?></p>
                        <p>
                            <strong>Статус:</strong>
                            <span class="status-label status-<?= mb_strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                <?= htmlspecialchars($order['status']) ?>
                            </span>
                        </p>
                        <p><strong>Деталі замовлення:</strong> <?= htmlspecialchars($order['details']) ?></p>
                        <p><strong>Дата створення:</strong> <?= htmlspecialchars($order['created_at']) ?></p>
                    </div>

                    <?php if ($disabled): ?>
                        <p class="info">Замовлення <?= htmlspecialchars($order['status']) ?>. Дії недоступні.</p>
                    <?php else: ?>
                        <?php if ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'junior_admin'): ?>
                            <form method="POST" action="update_status.php" class="status-form">
                                <input type="hidden" name="section" value="<?= $activeSection ?>">
                                <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <?php
                                $statuses = ['Новий', 'Очікується поставки товару', 'В роботі', 'Завершено', 'Неможливо виконати'];
                                ?>
                                <select name="status" class="status-select">
                                    <?php foreach ($statuses as $status): ?>
                                        <option value="<?= $status ?>" <?= $order['status'] === $status ? 'selected' : '' ?>>
                                            <?= $status ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" class="btn btn-primary">Змінити статус</button>
                            </form>
                        <?php endif; ?>
                    <?php endif; ?>

                    <?php if (!empty($comments[$order['id']])): ?>
                        <div class="comments-section">
                            <h4>Коментарі</h4>
                            <?php foreach ($comments[$order['id']] as $comment): ?>
                                <div class="comment <?= $comment['role'] === 'admin' ? 'admin-comment' : ($comment['role'] === 'junior_admin' ? 'junior-admin-comment' : '') ?>">
                                    <div class="comment-header">
                                        <span class="author">
                                            <?= htmlspecialchars($comment['username']) ?>
                                            <?php if ($comment['role'] === 'admin'): ?>
                                                (Адміністратор)
                                            <?php elseif ($comment['role'] === 'junior_admin'): ?>
                                                (Молодший Адміністратор)
                                            <?php endif; ?>
                                        </span>
                                        <span class="date"><?= $comment['created_at'] ?></span>
                                    </div>
                                    <p><?= htmlspecialchars($comment['content']) ?></p>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!$disabled && ($_SESSION['role'] === 'admin' || $_SESSION['role'] === 'junior_admin')): ?>
                        <form method="POST" action="add_comment.php" class="comment-form">
                            <input type="hidden" name="order_id" value="<?= $order['id'] ?>">
                            <input type="hidden" name="section" value="<?= $activeSection ?>">
                            <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                            <textarea name="content" placeholder="Ваш коментар..." required></textarea>
                            <button type="submit" class="btn btn-primary">Відповісти</button>
                        </form>
                    <?php endif; ?>

                    <?php if ($disabled): ?>
                        <div class="locked-overlay"><?= htmlspecialchars($order['status']) ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <!-- Users Section (admin only) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="users" style="display: <?= $activeSection === 'users' ? 'block' : 'none' ?>;">


            <h2>Керування користувачами</h2>
            <form method="GET" class="filter-group">
                <input type="hidden" name="section" value="users">
                <input type="text" name="user_search" placeholder="Пошук за логіном" value="<?= htmlspecialchars($userSearch) ?>">
                <button type="submit" class="btn btn-primary">Пошук</button>
            </form>
            <?php if (!empty($users)): ?>
                <div class="users-grid">
                    <?php foreach ($users as $user): ?>
                        <div class="user-card role-<?= str_replace('_', '-', $user['role']) ?>">
                            <form method="POST" action="update_user.php">
                                <input type="hidden" name="section" value="<?= $activeSection ?>">
                                <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                <div class="form-group">
                                    <label>Логін:</label>
                                    <input type="text" name="username" value="<?= htmlspecialchars($user['username']) ?>" required>
                                </div>
                                <div class="form-group">
                                    <label>Роль:</label>
                                    <select name="role" class="role-select">
                                        <option value="admin" <?= $user['role'] === 'admin' ? 'selected' : '' ?>>Адміністратор</option>
                                        <option value="junior_admin" <?= $user['role'] === 'junior_admin' ? 'selected' : '' ?>>Молодший адмін</option>
                                        <option value="user" <?= $user['role'] === 'user' ? 'selected' : '' ?>>Користувач</option>
                                    </select>
                                </div>
                                <button type="submit" class="btn btn-primary">Оновити</button>
                                <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $user['id'] ?>)">Видалити</button>
                            </form>
                            <?php if (isset($user['blocked_until']) && strtotime($user['blocked_until']) > time()): ?>
                                <p class="blocked-info">
                                    Заблоковано до: <?= htmlspecialchars($user['blocked_until']) ?><br>
                                    Причина: <?= htmlspecialchars($user['block_reason']) ?>
                                </p>
                                <button type="button" class="btn btn-success" onclick="unblockUser(<?= $user['id'] ?>)">Розблокувати</button>
                            <?php else: ?>
                                <button type="button" class="btn btn-warning" onclick="toggleBlockForm(<?= $user['id'] ?>)">Заблокувати</button>
                                <div id="block-form-<?= $user['id'] ?>" style="display:none; margin-top: 10px;">
                                    <form method="POST" action="block_user.php">
                                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                                        <input type="hidden" name="user_id" value="<?= $user['id'] ?>">
                                        <div class="form-group">
                                            <label>Причина блокування:</label>
                                            <input type="text" name="block_reason" required>
                                        </div>
                                        <div class="form-group">
                                            <label>Час блокування (до):</label>
                                            <input type="datetime-local" name="blocked_until" required>
                                        </div>
                                        <button type="submit" class="btn btn-danger">Заблокувати</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <p>Користувачів не знайдено.</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <!-- Logs Section (admin only) -->
    <?php if ($_SESSION['role'] === 'admin'): ?>
        <section id="logs" style="display: <?= $activeSection === 'logs' ? 'block' : 'none' ?>;">
            <h2>Системні логи</h2>

            <form method="GET" class="filter-group">
                <input type="hidden" name="section" value="logs">
                <input type="text" name="log_user" placeholder="Фільтр за користувачем" value="<?= htmlspecialchars($logUser) ?>">
                <input type="date" name="log_date" value="<?= htmlspecialchars($logDate) ?>" title="Оберіть дату">
                <input type="text" name="log_action" placeholder="Фільтр за діями" value="<?= htmlspecialchars($logAction) ?>">
                <button type="submit">
                    Фільтрувати
                </button>
            </form>

            <?php if (!empty($logs)): ?>
                <div class="logs-table">
                    <table>
                        <thead>
                        <tr>
                            <th>Дата та час</th>
                            <th>Користувач</th>
                            <th>Дія</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="log-date"><?= htmlspecialchars($log['created_at']) ?></td>
                                <td class="log-user">
                <span class="log-username <?= $log['role'] === 'admin' ? 'admin-username' : ($log['role'] === 'junior_admin' ? 'junior-admin-username' : '') ?>">
                    <?php if ($log['role'] === 'admin'): ?>
                        <svg class="crown-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M5 16L3 5l5.5 5L12 4l3.5 6L21 5l-2 11H5zm14 3c0 .6-.4 1-1 1H6c-.6 0-1-.4-1-1v-1h14v1z"/>
                        </svg>
                    <?php endif; ?>
                    <?php if ($log['role'] === 'junior_admin'): ?>
                        <svg class="star-icon" viewBox="0 0 24 24" fill="currentColor">
                            <path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/>
                        </svg>
                    <?php endif; ?>
                    <?= htmlspecialchars($log['username']) ?>
                </span>
                                    <?php if (isset($log['role'])): ?>
                                        <?php if ($log['role'] === 'admin'): ?>
                                            <span class="user-role-badge user-role-admin">
                            <svg class="role-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 1L3 5v6c0 5.55 3.84 10.74 9 12 5.16-1.26 9-6.45 9-12V5l-9-4zm0 2.18l7 3.12v5.7c0 4.83-3.4 9.16-7 10.5-3.6-1.34-7-5.67-7-10.5V6.3l7-3.12z"/>
                                <path d="M11 7h2v6h-2zm0 8h2v2h-2z"/>
                            </svg>
                            Адміністратор
                        </span>
                                        <?php elseif ($log['role'] === 'junior_admin'): ?>
                                            <span class="user-role-badge user-role-junior-admin">
                            <svg class="role-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z"/>
                                <path d="M12 5v2m0 10v2M5 12h2m10 0h2"/>
                            </svg>
                            Мол. Адміністратор
                        </span>
                                        <?php else: ?>
                                            <span class="user-role-badge user-role-user">
                            <svg class="role-icon" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M12 12c2.21 0 4-1.79 4-4s-1.79-4-4-4-4 1.79-4 4 1.79 4 4 4zm0 2c-2.67 0-8 1.34-8 4v2h16v-2c0-2.66-5.33-4-8-4z"/>
                            </svg>
                            Користувач
                        </span>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                                <td class="log-action" data-type="<?= strtolower(explode(' ', $log['action'])[0]) ?>">
                                    <?= htmlspecialchars($log['action']) ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>

                    <?php if ($totalPages > 1): ?>
                        <div class="pagination">
                            <?php for ($i = 1; $i <= $totalPages; $i++): ?>
                                <?php
                                $queryParams = $_GET;
                                $queryParams['log_page'] = $i;
                                $queryString = http_build_query($queryParams);
                                ?>
                                <a href="?<?= $queryString ?>"
                                   class="<?= $i == $logPage ? 'active' : '' ?>">
                                    <?= $i ?>
                                </a>
                            <?php endfor; ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <p class="no-logs">Логи не знайдено</p>
            <?php endif; ?>
        </section>
    <?php endif; ?>
</div>
</body>
</html>

<script>
    document.addEventListener('DOMContentLoaded', function() {
        const activeSection = new URLSearchParams(window.location.search).get('section') || 'orders';
        document.querySelectorAll('section').forEach(s => s.style.display = 'none');
        document.querySelector('#' + activeSection).style.display = 'block';

        document.querySelectorAll('.admin-sections a').forEach(link => {
            link.addEventListener('click', function(e) {
                e.preventDefault();
                const section = this.getAttribute('data-section');
                window.history.pushState({}, '', '?section=' + section);
                document.querySelectorAll('section').forEach(s => s.style.display = 'none');
                document.querySelector('#' + section).style.display = 'block';
            });
        });
    });

    function toggleBlockForm(userId) {
        const form = document.getElementById('block-form-' + userId);
        form.style.display = (form.style.display === 'none' || form.style.display === '') ? 'block' : 'none';
    }
    function unblockUser(userId) {
        if (confirm('Розблокувати користувача?')) {
            window.location.href = '/admin_panel/unblock_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent("<?= $_SESSION['csrf_token'] ?>");
        }
    }
    function confirmDelete(userId) {
        if (confirm('Видалити користувача?')) {
            window.location.href = '/admin_panel/delete_user.php?id=' + userId + '&csrf_token=' + encodeURIComponent("<?= $_SESSION['csrf_token'] ?>");
        }
    }
    // Функція для отримання поточного локального часу
    function getCurrentLocalTime() {
        const now = new Date();
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hours = String(now.getHours()).padStart(2, '0');
        const minutes = String(now.getMinutes()).padStart(2, '0');
        const seconds = String(now.getSeconds()).padStart(2, '0');

        return `${year}-${month}-${day} ${hours}:${minutes}:${seconds}`;
    }

    // Функція оновлення часу
    function updateTime() {
        const timeString = getCurrentLocalTime();
        document.querySelectorAll('.time-value').forEach(el => {
            el.textContent = timeString;
        });
    }

    // Запускаємо оновлення часу кожну секунду
    setInterval(updateTime, 1000);

    // Початкове оновлення часу
    updateTime();
    document.addEventListener('DOMContentLoaded', function() {
        // Перемикач мобільного меню
        const menuToggle = document.querySelector('.mobile-menu-toggle');
        const mobileNav = document.querySelector('.mobile-nav');


        // Адаптація таблиць
        const tables = document.querySelectorAll('table');
        tables.forEach(table => {
            table.style.minWidth = '600px'; // Мінімальна ширина для прокрутки
        });

        // Оптимізація вводу для мобільних
        if ('pointer' in navigator && navigator.pointer === 'coarse') {
            document.querySelectorAll('input, select, textarea').forEach(el => {
                el.style.fontSize = '16px'; // Запобігає збільшенню на iOS
            });
        }
    });

    // 🟡 Оновлюємо існуючий скрипт для мобільної навігації
    document.querySelectorAll('.admin-sections a').forEach(link => {
        link.addEventListener('click', function(e) {
            if(window.innerWidth <= 768) {
                e.preventDefault();
                const section = this.getAttribute('data-section');
                document.querySelectorAll('section').forEach(s => s.style.display = 'none');
                document.querySelector('#' + section).style.display = 'block';
                mobileNav.style.display = 'none'; // Сховати меню після кліку
            }
        });
    });

</script>
