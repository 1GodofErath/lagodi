<div id="dashboard-content" class="tab-content active">
    <!-- Блок статистики -->
    <div class="stats-grid">
        <div class="stats-card">
            <div class="stats-card-icon blue">
                <i class="fas fa-clipboard-list"></i>
            </div>
            <div class="stats-card-value"><?= $total_orders ?></div>
            <div class="stats-card-label">Усього замовлень</div>
            <div class="stats-card-progress">
                <div class="stats-card-progress-bar blue" style="width: 100%;"></div>
            </div>
            <div class="stats-card-meta">Загальна кількість замовлень</div>
        </div>

        <div class="stats-card">
            <div class="stats-card-icon green">
                <i class="fas fa-check-circle"></i>
            </div>
            <div class="stats-card-value"><?= $completed_orders ?></div>
            <div class="stats-card-label">Завершені замовлення</div>
            <div class="stats-card-progress">
                <div class="stats-card-progress-bar green" style="width: <?= $total_orders > 0 ? ($completed_orders / $total_orders * 100) : 0 ?>%;"></div>
            </div>
            <div class="stats-card-meta">
                <?= $total_orders > 0 ? round(($completed_orders / $total_orders) * 100, 1) : 0 ?>% від загальної кількості
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-card-icon orange">
                <i class="fas fa-clock"></i>
            </div>
            <div class="stats-card-value"><?= $active_orders_count ?></div>
            <div class="stats-card-label">Активні замовлення</div>
            <div class="stats-card-progress">
                <div class="stats-card-progress-bar orange" style="width: <?= $total_orders > 0 ? ($active_orders_count / $total_orders * 100) : 0 ?>%;"></div>
            </div>
            <div class="stats-card-meta">
                <?= $total_orders > 0 ? round(($active_orders_count / $total_orders) * 100, 1) : 0 ?>% від загальної кількості
            </div>
        </div>

        <div class="stats-card">
            <div class="stats-card-icon red">
                <i class="fas fa-bell"></i>
            </div>
            <div class="stats-card-value"><?= $total_notifications ?></div>
            <div class="stats-card-label">Непрочитані сповіщення</div>
            <div class="stats-card-progress">
                <div class="stats-card-progress-bar red" style="width: 100%;"></div>
            </div>
            <div class="stats-card-meta">
                <?php if ($total_notifications > 0): ?>
                    <a href="#notifications" class="view-all-link" data-tab="notifications">Переглянути всі</a>
                <?php else: ?>
                    Немає нових сповіщень
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="row">
        <!-- Останні замовлення -->
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Останні замовлення</h3>
                    <div class="card-actions">
                        <a href="#orders" class="btn btn-sm" data-tab="orders">Переглянути всі</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <?php if (count($orders) > 0): ?>
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                <tr>
                                    <th>№</th>
                                    <th>Послуга</th>
                                    <th>Статус</th>
                                    <th>Дата</th>
                                    <th>Дії</th>
                                </tr>
                                </thead>
                                <tbody>
                                <?php
                                $recent_orders = array_slice($orders, 0, 5);
                                foreach ($recent_orders as $order):
                                    ?>
                                    <tr>
                                        <td>#<?= $order['id'] ?></td>
                                        <td><?= htmlspecialchars($order['service']) ?></td>
                                        <td>
                                            <span class="status-badge <?= getStatusClass($order['status']) ?>">
                                                <?= htmlspecialchars($order['status']) ?>
                                            </span>
                                        </td>
                                        <td><?= formatDate($order['created_at']) ?></td>
                                        <td>
                                            <div class="table-actions">
                                                <button class="btn-icon view-order-details" data-id="<?= $order['id'] ?>" title="Деталі">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (isOrderEditable($order['status'])): ?>
                                                    <button class="btn-icon edit-order" data-id="<?= $order['id'] ?>" title="Редагувати">
                                                        <i class="fas fa-edit"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php else: ?>
                        <div class="empty-state-container">
                            <div class="empty-state-icon">
                                <i class="fas fa-clipboard-list"></i>
                            </div>
                            <h3>Немає замовлень</h3>
                            <p>У вас ще немає жодного замовлення. Створіть нове для початку роботи.</p>
                            <a href="#new-order" class="btn btn-primary" data-tab="new-order">Створити замовлення</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Останні сповіщення -->
        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Останні сповіщення</h3>
                    <div class="card-actions">
                        <a href="#notifications" class="btn btn-sm" data-tab="notifications">Переглянути всі</a>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="notifications-list dashboard-notifications">
                        <?php if (count($unread_notifications) > 0): ?>
                            <?php
                            $recent_notifications = array_slice($unread_notifications, 0, 5);
                            foreach ($recent_notifications as $notification):
                                ?>
                                <div class="notification-item" data-id="<?= $notification['id'] ?>" data-order-id="<?= $notification['order_id'] ?>">
                                    <div class="notification-icon">
                                        <i class="<?= getNotificationIcon($notification['type']) ?>"></i>
                                    </div>
                                    <div class="notification-content">
                                        <div class="notification-title"><?= htmlspecialchars($notification['title']) ?></div>
                                        <div class="notification-message"><?= htmlspecialchars($notification['description'] ?? $notification['content']) ?></div>
                                        <div class="notification-meta">
                                            <span class="notification-time"><?= formatTimeAgo($notification['created_at']) ?></span>
                                            <?php if (!empty($notification['service'])): ?>
                                                <span class="notification-service"><?= htmlspecialchars($notification['service']) ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-state">
                                <div class="empty-state-icon">
                                    <i class="fas fa-check-circle"></i>
                                </div>
                                <p>Немає нових сповіщень</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Швидкі дії -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Швидкі дії</h3>
        </div>
        <div class="card-body">
            <div class="quick-actions">
                <div class="quick-action">
                    <div class="quick-action-icon">
                        <i class="fas fa-plus"></i>
                    </div>
                    <div class="quick-action-content">
                        <h4 class="quick-action-title">Нове замовлення</h4>
                        <p class="quick-action-description">Створіть нове замовлення на ремонт або обслуговування</p>
                    </div>
                </div>

                <div class="quick-action">
                    <div class="quick-action-icon">
                        <i class="fas fa-user-cog"></i>
                    </div>
                    <div class="quick-action-content">
                        <h4 class="quick-action-title">Налаштування профілю</h4>
                        <p class="quick-action-description">Змінюйте особисті дані, фото профілю або пароль</p>
                    </div>
                </div>

                <div class="quick-action">
                    <div class="quick-action-icon">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="quick-action-content">
                        <h4 class="quick-action-title">Допомога</h4>
                        <p class="quick-action-description">Отримайте відповіді на питання та зв'яжіться з підтримкою</p>
                    </div>
                </div>

                <div class="quick-action">
                    <div class="quick-action-icon">
                        <i class="fas fa-file-alt"></i>
                    </div>
                    <div class="quick-action-content">
                        <h4 class="quick-action-title">Інструкції</h4>
                        <p class="quick-action-description">Перегляд довідкових матеріалів та інструкцій користувача</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>