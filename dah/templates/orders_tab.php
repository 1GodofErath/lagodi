<div class="page-header">
    <h1>Мої замовлення</h1>
    <div class="page-actions">
        <a href="?tab=new-order" class="btn btn-primary">
            <i class="fas fa-plus"></i> Нове замовлення
        </a>
    </div>
</div>

<div class="filters-container">
    <form id="filter-form" method="get" action="" class="filters-form">
        <input type="hidden" name="tab" value="orders">

        <div class="filter-group">
            <label for="status-filter" class="filter-label">Статус</label>
            <select id="status-filter" name="status" class="filter-select">
                <option value="">Всі статуси</option>
                <?php foreach ($allStatuses as $status): ?>
                    <option value="<?= htmlspecialchars($status) ?>" <?= $filterStatus === $status ? 'selected' : '' ?>>
                        <?= htmlspecialchars($status) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="service-filter" class="filter-label">Послуга</label>
            <select id="service-filter" name="service" class="filter-select">
                <option value="">Всі послуги</option>
                <?php foreach ($allServices as $service): ?>
                    <option value="<?= htmlspecialchars($service) ?>" <?= $filterService === $service ? 'selected' : '' ?>>
                        <?= htmlspecialchars($service) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="search-input" class="filter-label">Пошук</label>
            <div class="search-input-container">
                <input type="text" id="search-input" name="search" value="<?= htmlspecialchars($searchQuery) ?>"
                       class="filter-input" placeholder="Номер, пристрій або опис...">
                <button type="submit" class="search-btn"><i class="fas fa-search"></i></button>
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="btn btn-primary btn-sm">Фільтрувати</button>
            <button type="button" id="reset-filters" class="btn btn-secondary btn-sm">Скинути</button>
        </div>
    </form>
</div>

<?php if (empty($orders)): ?>
    <div class="empty-state">
        <i class="fas fa-clipboard"></i>
        <h3>Замовлення відсутні</h3>
        <p>У вас ще немає жодного замовлення або немає замовлень, що відповідають обраним фільтрам.</p>
        <a href="?tab=new-order" class="btn btn-primary">Створити замовлення</a>
    </div>
<?php else: ?>
    <div class="orders-list">
        <?php foreach ($orders as $orderItem): ?>
            <div class="order-card<?= $orderItem['unread_count'] > 0 ? ' has-unread' : '' ?>">
                <div class="order-header">
                    <div class="order-id">
                        #<?= $orderItem['id'] ?>
                        <?php if ($orderItem['unread_count'] > 0): ?>
                            <span class="badge"><?= $orderItem['unread_count'] ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="order-date"><?= formatDateTime($orderItem['created_at']) ?></div>
                </div>

                <div class="order-body">
                    <div class="order-details">
                        <div class="order-detail">
                            <div class="order-detail-label">Послуга</div>
                            <div class="order-detail-value"><?= htmlspecialchars($orderItem['service']) ?></div>
                        </div>

                        <div class="order-detail">
                            <div class="order-detail-label">Пристрій</div>
                            <div class="order-detail-value"><?= htmlspecialchars($orderItem['device_type']) ?></div>
                        </div>

                        <div class="order-detail">
                            <div class="order-detail-label">Статус</div>
                            <div class="order-detail-value">
                        <span class="status-badge <?= getStatusClass($orderItem['status']) ?>">
                            <?= htmlspecialchars($orderItem['status']) ?>
                        </span>
                            </div>
                        </div>

                        <?php if ($orderItem['estimated_completion_date']): ?>
                            <div class="order-detail">
                                <div class="order-detail-label">Очікувана дата завершення</div>
                                <div class="order-detail-value"><?= formatDate($orderItem['estimated_completion_date']) ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if ($orderItem['price'] > 0): ?>
                            <div class="order-detail">
                                <div class="order-detail-label">Вартість</div>
                                <div class="order-detail-value"><?= number_format($orderItem['price'], 2, '.', ' ') ?> грн</div>
                            </div>
                        <?php endif; ?>
                    </div>

                    <?php if (!empty($orderItem['files'])): ?>
                        <div class="order-files">
                            <div class="files-heading">
                                <span>Файли</span>
                                <span class="count"><?= count($orderItem['files']) ?></span>
                            </div>
                            <div class="files-grid">
                                <?php
                                $displayLimit = 4;
                                $fileCount = count($orderItem['files']);
                                $showCount = min($displayLimit, $fileCount);

                                for ($i = 0; $i < $showCount; $i++):
                                    $file = $orderItem['files'][$i];
                                    $isImage = $file['file_type'] === 'image' || strpos($file['file_mime'], 'image/') === 0;
                                    ?>
                                    <div class="file-item">
                                        <div class="file-thumbnail">
                                            <?php if ($isImage): ?>
                                                <img src="<?= htmlspecialchars($file['file_path']) ?>" alt="<?= htmlspecialchars($file['original_name']) ?>">
                                            <?php else: ?>
                                                <i class="fas fa-file"></i>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                <?php endfor; ?>

                                <?php if ($fileCount > $displayLimit): ?>
                                    <div class="file-item more-files">
                                        <div class="file-thumbnail">
                                            <span>+<?= $fileCount - $displayLimit ?></span>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($orderItem['comments'])): ?>
                        <div class="order-comments">
                            <div class="comments-heading">
                                <span>Коментарі</span>
                                <span class="count"><?= count($orderItem['comments']) ?></span>
                            </div>
                            <div class="comments-list">
                                <?php
                                $comment = $orderItem['comments'][0];
                                $isAdmin = $comment['is_admin'] == 1 || $comment['author_role'] === 'admin';
                                ?>
                                <div class="comment<?= $isAdmin ? ' admin-comment' : '' ?>">
                                    <div class="comment-header">
                                        <div class="comment-author"><?= htmlspecialchars($comment['author_display_name'] ?? $comment['author_name'] ?? 'Користувач') ?></div>
                                        <div class="comment-date"><?= formatTimeAgo($comment['created_at']) ?></div>
                                    </div>
                                    <div class="comment-text"><?= htmlspecialchars(mb_substr($comment['comment'], 0, 100)) . (mb_strlen($comment['comment']) > 100 ? '...' : '') ?></div>
                                </div>

                                <?php if (count($orderItem['comments']) > 1): ?>
                                    <div class="more-comments">
                                        <span>Ще <?= count($orderItem['comments']) - 1 ?> <?= numWord(count($orderItem['comments']) - 1, ['коментар', 'коментарі', 'коментарів']) ?></span>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="order-footer">
                    <div class="order-actions">
                        <button type="button" class="btn btn-primary btn-sm view-order-btn" data-id="<?= $orderItem['id'] ?>">
                            <i class="fas fa-eye"></i> Переглянути
                        </button>

                        <?php if ($order->isOrderEditable($orderItem['status'])): ?>
                            <button type="button" class="btn btn-secondary btn-sm edit-order-btn" data-id="<?= $orderItem['id'] ?>">
                                <i class="fas fa-edit"></i> Редагувати
                            </button>
                        <?php endif; ?>

                        <?php if ($order->isOrderCancellable($orderItem['status'])): ?>
                            <button type="button" class="btn btn-danger btn-sm cancel-order-btn" data-id="<?= $orderItem['id'] ?>">
                                <i class="fas fa-times"></i> Скасувати
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <!-- Пагінація -->
    <?php if ($pagination['pages'] > 1): ?>
        <div class="pagination">
            <ul class="pagination-list">
                <?php if ($pagination['current'] > 1): ?>
                    <li class="pagination-item">
                        <a href="?tab=orders&page=<?= $pagination['current'] - 1 ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?><?= $filterService ? '&service=' . urlencode($filterService) : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>" class="pagination-link">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                <?php endif; ?>

                <?php
                // Визначаємо діапазон сторінок для відображення
                $startPage = max(1, $pagination['current'] - 2);
                $endPage = min($pagination['pages'], $pagination['current'] + 2);

                // Якщо поточна сторінка близько до початку, показуємо більше сторінок після
                if ($startPage <= 3) {
                    $endPage = min($pagination['pages'], 5);
                }

                // Якщо поточна сторінка близько до кінця, показуємо більше сторінок до
                if ($endPage >= $pagination['pages'] - 2) {
                    $startPage = max(1, $pagination['pages'] - 4);
                }

                // Показуємо першу сторінку та "..."
                if ($startPage > 1) {
                    echo '<li class="pagination-item"><a href="?tab=orders&page=1' .
                        ($filterStatus ? '&status=' . urlencode($filterStatus) : '') .
                        ($filterService ? '&service=' . urlencode($filterService) : '') .
                        ($searchQuery ? '&search=' . urlencode($searchQuery) : '') .
                        '" class="pagination-link">1</a></li>';

                    if ($startPage > 2) {
                        echo '<li class="pagination-item"><span class="pagination-link disabled">...</span></li>';
                    }
                }

                // Показуємо діапазон сторінок
                for ($i = $startPage; $i <= $endPage; $i++):
                    ?>
                    <li class="pagination-item">
                        <a href="?tab=orders&page=<?= $i ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?><?= $filterService ? '&service=' . urlencode($filterService) : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>" class="pagination-link <?= $i === $pagination['current'] ? 'active' : '' ?>">
                            <?= $i ?>
                        </a>
                    </li>
                <?php endfor; ?>

                <?php
                // Показуємо "..." та останню сторінку
                if ($endPage < $pagination['pages']) {
                    if ($endPage < $pagination['pages'] - 1) {
                        echo '<li class="pagination-item"><span class="pagination-link disabled">...</span></li>';
                    }

                    echo '<li class="pagination-item"><a href="?tab=orders&page=' . $pagination['pages'] .
                        ($filterStatus ? '&status=' . urlencode($filterStatus) : '') .
                        ($filterService ? '&service=' . urlencode($filterService) : '') .
                        ($searchQuery ? '&search=' . urlencode($searchQuery) : '') .
                        '" class="pagination-link">' . $pagination['pages'] . '</a></li>';
                }
                ?>

                <?php if ($pagination['current'] < $pagination['pages']): ?>
                    <li class="pagination-item">
                        <a href="?tab=orders&page=<?= $pagination['current'] + 1 ?><?= $filterStatus ? '&status=' . urlencode($filterStatus) : '' ?><?= $filterService ? '&service=' . urlencode($filterService) : '' ?><?= $searchQuery ? '&search=' . urlencode($searchQuery) : '' ?>" class="pagination-link">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                <?php endif; ?>
            </ul>
        </div>
    <?php endif; ?>

<?php endif; ?>