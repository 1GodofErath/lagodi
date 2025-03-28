<div class="tab-content" id="orders-content">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">Мої замовлення</h2>
        </div>

        <div class="filters-bar">
            <form action="" method="get" class="filter-form" id="filter-form">
                <div class="filter-group">
                    <select name="status" class="form-control">
                        <option value="">Всі статуси</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= htmlspecialchars($status) ?>" <?= $filter_status === $status ? 'selected' : '' ?>>
                                <?= htmlspecialchars($status) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <select name="service" class="form-control">
                        <option value="">Всі послуги</option>
                        <?php foreach ($services as $service): ?>
                            <option value="<?= htmlspecialchars($service) ?>" <?= $filter_service === $service ? 'selected' : '' ?>>
                                <?= htmlspecialchars($service) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="search-bar">
                        <input type="text" name="search" class="form-control" placeholder="Пошук замовлень..." value="<?= htmlspecialchars($search_query) ?>">
                    </div>

                    <button type="submit" class="btn">
                        <i class="fas fa-filter"></i> Фільтрувати
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($orders)): ?>
            <div class="orders-list">
                <?php foreach ($orders as $order):
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
                            <div class="order-detail">
                                <div class="order-detail-label">Контактний телефон:</div>
                                <div><?= htmlspecialchars($order['phone']) ?></div>
                            </div>
                            <?php if (!empty($order['address'])): ?>
                                <div class="order-detail">
                                    <div class="order-detail-label">Адреса:</div>
                                    <div><?= htmlspecialchars($order['address']) ?></div>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($order['delivery_method'])): ?>
                                <div class="order-detail">
                                    <div class="order-detail-label">Спосіб доставки:</div>
                                    <div><?= htmlspecialchars($order['delivery_method']) ?></div>
                                </div>
                            <?php endif; ?>

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

                            <?php if (!empty($order['files'])): ?>
                                <div class="order-files">
                                    <div class="order-detail-label">Файли:</div>
                                    <div class="file-list">
                                        <?php foreach ($order['files'] as $file): ?>
                                            <div class="file-item">
                                                <?php
                                                $ext = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                                                $icon = 'fa-file';
                                                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif'])) {
                                                    $icon = 'fa-file-image';
                                                } elseif (in_array($ext, ['mp4', 'avi', 'mov'])) {
                                                    $icon = 'fa-file-video';
                                                } elseif (in_array($ext, ['pdf'])) {
                                                    $icon = 'fa-file-pdf';
                                                } elseif (in_array($ext, ['doc', 'docx'])) {
                                                    $icon = 'fa-file-word';
                                                } elseif (in_array($ext, ['txt'])) {
                                                    $icon = 'fa-file-alt';
                                                }
                                                ?>
                                                <i class="fas <?= $icon ?> file-icon"></i>
                                                <span class="file-name"><?= htmlspecialchars($file['file_name']) ?></span>
                                                <div class="file-actions">
                                                    <button class="btn btn-sm view-file" data-path="<?= htmlspecialchars($file['file_path']) ?>" data-filename="<?= htmlspecialchars($file['file_name']) ?>">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                    <a class="btn btn-sm" href="<?= htmlspecialchars($file['file_path']) ?>" download="<?= htmlspecialchars($file['file_name']) ?>">
                                                        <i class="fas fa-download"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (!empty($order['comments'])): ?>
                                <div class="comments-section">
                                    <div class="order-detail-label">Коментарі адміністратора:</div>
                                    <?php foreach ($order['comments'] as $comment): ?>
                                        <div class="comment">
                                            <div class="comment-header">
                                                <span class="comment-author"><?= htmlspecialchars($comment['admin_name'] ?? 'Адмін') ?></span>
                                                <span class="comment-date local-time" data-utc="<?= $comment['created_at'] ?>">
                                                    <?= date('d.m.Y H:i', strtotime($comment['created_at'])) ?>
                                                </span>
                                            </div>
                                            <div class="comment-body">
                                                <?= nl2br(htmlspecialchars($comment['content'])) ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($order['user_comment']): ?>
                                <div class="user-comment-section">
                                    <div class="order-detail-label">Ваш коментар:</div>
                                    <div class="comment">
                                        <?= nl2br(htmlspecialchars($order['user_comment'])) ?>
                                        <?php if (!$order['is_closed'] && !$block_message): ?>
                                            <button class="btn btn-sm delete-comment" data-id="<?= $order['id'] ?>" title="Видалити коментар" style="float:right; margin-top: 5px;">
                                                <i class="fas fa-trash-alt"></i>
                                            </button>
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
            </div>
        <?php else: ?>
            <p>Замовлення не знайдені.</p>
        <?php endif; ?>
    </div>
</div>