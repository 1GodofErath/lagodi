<div class="tab-content active" id="dashboard-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Статистика</h2>
        </div>
        <div class="order-stats">
            <p>Активних замовлень: <?= $active_orders_count ?></p>
            <p>Всього замовлень: <?= count($orders) ?></p>
            <?php if ($total_notifications > 0): ?>
                <p>Непрочитаних сповіщень: <?= $total_notifications ?></p>
            <?php endif; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Останні замовлення</h2>
            <a href="#orders" class="btn-text view-all" data-tab="orders">Переглянути всі</a>
        </div>
        <?php
        $recent_orders = array_slice($orders, 0, 3);
        if (!empty($recent_orders)):
            foreach ($recent_orders as $order):
                $has_notifications = $order['unread_count'] > 0;
                ?>
                <div class="order-card <?= $has_notifications ? 'has-notifications' : '' ?>" data-order-id="<?= $order['id'] ?>">
                    <div class="order-header">
                        <h3 class="order-id">
                            Замовлення #<?= $order['id'] ?>
                            <?php if ($has_notifications): ?>
                                <span class="notification-indicator" title="Нові сповіщення"></span>
                            <?php endif; ?>
                        </h3>
                        <div class="order-meta">
                            <span class="status-badge status-<?= strtolower(str_replace(' ', '-', $order['status'])) ?>">
                                <?= htmlspecialchars($order['status']) ?>
                            </span>
                            <span class="order-date">
                                <i class="far fa-calendar-alt"></i>
                                <span class="local-time" data-utc="<?= $order['created_at'] ?>">
                                    <?= date('d.m.Y H:i', strtotime($order['created_at'])) ?>
                                </span>
                            </span>
                        </div>
                    </div>
                    <div class="order-body">
                        <div class="order-detail">
                            <div class="order-detail-label">Послуга:</div>
                            <div><?= htmlspecialchars($order['service']) ?></div>
                        </div>
                        <div class="order-detail">
                            <div class="order-detail-label">Тип пристрою:</div>
                            <div><?= htmlspecialchars($order['device_type']) ?></div>
                        </div>
                        <div class="order-detail">
                            <div class="order-detail-label">Деталі:</div>
                            <div><?= nl2br(htmlspecialchars($order['details'])) ?></div>
                        </div>

                        <?php if ($has_notifications): ?>
                            <div class="status-update">
                                <i class="fas fa-bell status-update-icon"></i>
                                <div class="status-update-message">
                                    <?php if (isset($order['notifications']['status_changed']) && $order['notifications']['status_changed']): ?>
                                        <div>Статус замовлення змінено на: <strong><?= $order['notifications']['current_status'] ?></strong></div>
                                    <?php endif; ?>
                                    <?php if (!empty($order['notifications']['new_comments'])): ?>
                                        <div>Нові коментарі від адміністратора (<?= count($order['notifications']['new_comments']) ?>)</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="view-more-btn">Переглянути повну інформацію <i class="fas fa-chevron-down"></i></div>

                        <?php if (!$order['is_closed'] && !$block_message): ?>
                            <div class="order-actions">
                                <button class="btn btn-sm edit-order" data-id="<?= $order['id'] ?>">
                                    <i class="fas fa-edit"></i> Редагувати
                                </button>
                                <button class="btn btn-sm add-comment" data-id="<?= $order['id'] ?>">
                                    <i class="fas fa-comment"></i> Додати коментар
                                </button>
                            </div>
                        <?php elseif ($order['is_closed']): ?>
                            <div class="order-closed-notice">
                                <em>Замовлення завершено, редагування недоступне</em>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>Ви ще не маєте замовлень. Створіть нове замовлення.</p>
        <?php endif; ?>
    </div>
</div>