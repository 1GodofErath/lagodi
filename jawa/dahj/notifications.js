document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const mainWrapper = document.getElementById('mainWrapper');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('overlay');
    const themeSwitchBtn = document.getElementById('themeSwitchBtn');
    const toggleThemeButton = document.getElementById('toggleThemeButton');

    // Функція для показу Toast-сповіщень
    function showToast(type, message, title = '') {
        try {
            const toastContainer = document.getElementById('toast-container');
            if (!toastContainer) return;

            const toast = document.createElement('div');
            toast.className = `toast toast-${type}`;

            let icon = '';
            if (type === 'success') icon = '<i class="bi bi-check-circle-fill toast-icon"></i>';
            else if (type === 'error') icon = '<i class="bi bi-exclamation-triangle-fill toast-icon"></i>';
            else if (type === 'info') icon = '<i class="bi bi-info-circle-fill toast-icon"></i>';

            toast.innerHTML = `
                ${icon}
                <div class="toast-content">
                    ${title ? `<div class="toast-title">${title}</div>` : ''}
                    <div class="toast-message">${message}</div>
                </div>
                <button class="toast-close">&times;</button>
            `;

            toastContainer.appendChild(toast);

            // Додаємо обробник для закриття сповіщення
            const closeButton = toast.querySelector('.toast-close');
            if (closeButton) {
                closeButton.addEventListener('click', () => {
                    if (toastContainer.contains(toast)) {
                        toastContainer.removeChild(toast);
                    }
                });
            }

            // Автоматично закриваємо через 5 секунд
            setTimeout(() => {
                if (toastContainer && toastContainer.contains(toast)) {
                    toastContainer.removeChild(toast);
                }
            }, 5000);
        } catch (error) {
            console.error('Error showing toast:', error);
        }
    }

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

    // Функція для перемикання теми з плавним переходом
    function toggleTheme() {
        // Отримуємо поточну тему
        const htmlElement = document.documentElement;
        const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        // Додаємо клас для плавного переходу
        document.body.style.transition = 'background-color 0.3s ease, color 0.3s ease';

        // Змінюємо тему безпосередньо
        htmlElement.setAttribute('data-theme', newTheme);

        // Зберігаємо стан в localStorage і cookie
        localStorage.setItem('theme', newTheme);
        document.cookie = `theme=${newTheme}; path=/; max-age=2592000`; // ~30 днів

        // Змінюємо всі кнопки перемикання теми
        const themeSwitchers = document.querySelectorAll('.theme-switch-btn, #toggleThemeButton');
        themeSwitchers.forEach(switcher => {
            const icon = switcher.querySelector('i');
            if (icon) {
                icon.className = newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
            }

            // Якщо є текст, оновлюємо його також
            const text = switcher.textContent;
            if (text && text.includes('тема')) {
                switcher.innerHTML = `<i class="${newTheme === 'dark' ? 'bi bi-sun' : 'bi bi-moon'}"></i> ${newTheme === 'dark' ? 'Світла тема' : 'Темна тема'}`;
            }
        });

        // Зберігаємо поточний скрол
        const scrollPosition = window.pageYOffset;

        // Оновлюємо стилі елементів за необхідності
        document.querySelectorAll('.tab-item').forEach(tab => {
            if (tab.classList.contains('active')) {
                tab.style.boxShadow = newTheme === 'dark'
                    ? '0 0 15px rgba(255, 255, 255, 0.2)'
                    : '0 0 15px rgba(0, 0, 0, 0.1)';
            }
        });

        // Невелика затримка для анімації
        setTimeout(() => {
            // Після переходу - видаляємо стильове правило переходу
            document.body.style.transition = '';

            // Відновлюємо позицію скролу
            window.scrollTo(0, scrollPosition);
        }, 300);

        // Показуємо toast-повідомлення
        showToast('info', `Тему змінено на ${newTheme === 'dark' ? 'темну' : 'світлу'}`, 'Налаштування теми');
    }

    // Додаємо обробники подій для перемикання теми
    if (themeSwitchBtn) {
        themeSwitchBtn.addEventListener('click', toggleTheme);
    }

    if (toggleThemeButton) {
        toggleThemeButton.addEventListener('click', function(e) {
            e.preventDefault();
            toggleTheme();

            // Закриваємо випадаюче меню
            if (userDropdownMenu) {
                userDropdownMenu.classList.remove('show');
            }
        });
    }

    // Пошук в коментарях
    const searchInput = document.getElementById('comment-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const commentItems = document.querySelectorAll('.comment-item');

            commentItems.forEach(item => {
                const commentText = item.querySelector('.comment-text')?.textContent.toLowerCase() || '';
                const serviceName = item.querySelector('.comment-service-link')?.textContent.toLowerCase() || '';
                const commentId = item.querySelector('.comment-number')?.textContent.toLowerCase() || '';

                if (commentText.includes(searchTerm) || serviceName.includes(searchTerm) || commentId.includes(searchTerm)) {
                    item.style.display = '';
                } else {
                    item.style.display = 'none';
                }
            });

            // Показуємо або приховуємо кнопку "Переглянути більше"
            const viewMoreContainer = document.querySelector('.view-more-container');
            if (viewMoreContainer && searchTerm !== '') {
                viewMoreContainer.style.display = 'none';
            } else if (viewMoreContainer) {
                viewMoreContainer.style.display = '';
            }
        });
    }

    // Показ сповіщень залежно від URL параметрів
    const urlParams = new URLSearchParams(window.location.search);
    const successParam = urlParams.get('success');
    const errorParam = urlParams.get('error');

    if (successParam === 'marked_read') {
        showToast('success', 'Коментар позначено як прочитаний', 'Успіх');
    } else if (successParam === 'all_marked_read') {
        showToast('success', 'Всі коментарі позначено як прочитані', 'Успіх');
    }

    if (errorParam) {
        const errorMessages = {
            'not_found': 'Коментар не знайдено або доступ заборонено',
            'db_error': 'Виникла помилка бази даних. Спробуйте пізніше.'
        };

        const errorMessage = errorMessages[errorParam] || 'Виникла помилка';
        showToast('error', errorMessage, 'Помилка');
    }

    // Підсвічування активної вкладки
    const currentFilter = urlParams.get('filter') || 'default';
    const tabItems = document.querySelectorAll('.tab-item');

    tabItems.forEach(tab => {
        const href = tab.getAttribute('href');

        if (
            (currentFilter === 'default' && href === 'notifications.php') ||
            (href && href.includes(`filter=${currentFilter}`))
        ) {
            tab.classList.add('active');
            tab.style.boxShadow = '0 0 15px rgba(255, 255, 255, 0.2)';
        }
    });

    // Видалення параметрів успіху/помилки з URL, щоб сповіщення не з'являлися повторно при оновленні
    if (successParam || errorParam) {
        window.history.replaceState(
            {},
            document.title,
            window.location.pathname + window.location.search.replace(/[?&](success|error)=[^&]+/g, '')
        );
    }

    // Ефект при наведенні для блоків вгорі
    const commentTabs = document.querySelectorAll('.comments-tabs .tab-item');
    commentTabs.forEach(tab => {
        tab.addEventListener('mouseenter', function() {
            if (!this.classList.contains('active')) {
                this.style.transform = 'translateY(-3px)';
                this.style.boxShadow = '0 6px 12px rgba(0, 0, 0, 0.15)';
            }
        });

        tab.addEventListener('mouseleave', function() {
            if (!this.classList.contains('active')) {
                this.style.transform = '';
                this.style.boxShadow = '0 2px 8px rgba(0, 0, 0, 0.1)';
            }
        });
    });

    // Кнопка "Переглянути більше" - додаємо анімацію
    const viewMoreBtn = document.querySelector('.view-more-btn');
    if (viewMoreBtn) {
        viewMoreBtn.addEventListener('click', function(e) {
            // Додаємо анімацію кліку
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 150);

            // Продовжуємо за посиланням
            // Не блокуємо стандартну поведінку, щоб перейти за посиланням
        });
    }

    // Зміна розміру екрану - для адаптивного дизайну
    window.addEventListener('resize', function() {
        if (window.innerWidth >= 992 && sidebar) {
            if (sidebar.classList.contains('mobile-active')) {
                sidebar.classList.remove('mobile-active');
                if (overlay) overlay.classList.remove('active');
                document.body.classList.remove('no-scroll');
            }
        }
    });

    // Обробники подій для кнопок дій у коментарях
    document.querySelectorAll('.btn-read, .btn-view').forEach(btn => {
        btn.addEventListener('mouseenter', function() {
            this.style.opacity = '0.85';
        });

        btn.addEventListener('mouseleave', function() {
            this.style.opacity = '1';
        });

        btn.addEventListener('click', function() {
            this.style.transform = 'scale(0.95)';
            setTimeout(() => {
                this.style.transform = 'scale(1)';
            }, 100);
        });
    });

    // Покращена доступність - дозволяємо використовувати клавіатуру для навігації
    document.querySelectorAll('.tab-item, .filter-btn, .btn-read, .btn-view').forEach(el => {
        el.setAttribute('tabindex', '0');
        el.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                this.click();
            }
        });
    });
});
/**
 * JS для сторінки коментарів (notifications.php)
 * Шлях до файлу: ../../jawa/dahj/notifications.js
 *
 * Функціонал:
 * - Фільтрація та пошук в коментарях
 * - Позначення коментарів як прочитаних
 * - Підтримка світлої/темної теми
 */

document.addEventListener('DOMContentLoaded', function() {
    // Функція для створення Toast-сповіщень
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'}"></i>
                <span>${message}</span>
            </div>
            <i class="bi bi-x toast-close"></i>
        `;

        toastContainer.appendChild(toast);

        // Показуємо Toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);

        // Автоматично ховаємо через 5 секунд
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toastContainer.removeChild(toast);
            }, 300);
        }, 5000);

        // Обробник для кнопки закриття
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toastContainer.removeChild(toast);
                }, 300);
            });
        }
    }

    // Показуємо Toast при успішній дії, якщо є відповідний параметр в URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
        const successType = urlParams.get('success');

        if (successType === 'marked_read') {
            showToast('Коментар успішно позначено як прочитаний', 'success');
        } else if (successType === 'all_marked_read') {
            showToast('Всі коментарі позначені як прочитані', 'success');
        }
    }

    if (urlParams.has('error')) {
        const errorType = urlParams.get('error');

        if (errorType === 'not_found') {
            showToast('Коментар не знайдено', 'error');
        } else if (errorType === 'db_error') {
            showToast('Помилка бази даних. Спробуйте пізніше.', 'error');
        }
    }

    // Функціонал пошуку коментарів
    const searchInput = document.getElementById('comment-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const commentItems = document.querySelectorAll('.comment-item');

            commentItems.forEach(item => {
                const commentText = item.querySelector('.comment-text').textContent.toLowerCase();
                const commentService = item.querySelector('.comment-service-link').textContent.toLowerCase();
                const commentAuthor = item.querySelector('.author-name').textContent.toLowerCase();

                const isVisible =
                    commentText.includes(searchTerm) ||
                    commentService.includes(searchTerm) ||
                    commentAuthor.includes(searchTerm);

                item.style.display = isVisible ? 'block' : 'none';
            });

            // Якщо немає видимих коментарів, показуємо повідомлення
            const visibleComments = document.querySelectorAll('.comment-item[style="display: block;"]');
            const commentsList = document.querySelector('.comments-list');
            const noResultsElement = document.querySelector('.no-search-results');

            if (commentsList && visibleComments.length === 0 && searchTerm.length > 0) {
                if (!noResultsElement) {
                    const noResults = document.createElement('div');
                    noResults.className = 'no-search-results';
                    noResults.innerHTML = `
                        <i class="bi bi-search"></i>
                        <p>Немає результатів за запитом "${searchTerm}"</p>
                    `;
                    commentsList.appendChild(noResults);
                } else {
                    noResultsElement.querySelector('p').textContent = `Немає результатів за запитом "${searchTerm}"`;
                    noResultsElement.style.display = 'flex';
                }
            } else if (noResultsElement) {
                noResultsElement.style.display = 'none';
            }
        });
    }

    // Функція для застосування активного стану кнопці фільтру при кліку
    const filterButtons = document.querySelectorAll('.filter-btn');
    filterButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Додаємо клас active лише при навігації через кнопки
            if (!this.classList.contains('btn-clear')) {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    // Кнопки для позначення коментарів прочитаними
    const markReadButtons = document.querySelectorAll('.btn-read');
    markReadButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // Предотвращаем множественные клики
            if (this.classList.contains('processing')) {
                e.preventDefault();
                return;
            }

            this.classList.add('processing');
            this.innerHTML = '<i class="bi bi-hourglass"></i> Обробка...';
        });
    });

    // Функціонал кнопки "Позначити всі прочитаними"
    const markAllReadButton = document.querySelector('a.filter-btn.btn-clear');
    if (markAllReadButton) {
        markAllReadButton.addEventListener('click', function(e) {
            // Запобігаємо множинним кликам
            if (this.classList.contains('processing')) {
                e.preventDefault();
                return;
            }

            this.classList.add('processing');
            this.innerHTML = '<i class="bi bi-hourglass"></i> Обробка...';
        });
    }
});

/**
 * Функції для роботи з темою інтерфейсу
 */
function getThemePreference() {
    // Спочатку перевіряємо localStorage
    let theme = localStorage.getItem('theme');

    // Якщо нема в localStorage, перевіряємо cookies
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

    // Якщо все ще нема, використовуємо системні налаштування або dark за замовчуванням
    if (!theme) {
        if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            theme = 'dark';
        } else {
            theme = 'light';
        }
    }

    return theme;
}

// Додаємо слухач змін системної теми
if (window.matchMedia) {
    const colorSchemeQuery = window.matchMedia('(prefers-color-scheme: dark)');

    colorSchemeQuery.addEventListener('change', (e) => {
        // Перевіряємо, чи користувач явно не встановив тему
        if (!localStorage.getItem('theme') && !document.cookie.includes('theme=')) {
            const newTheme = e.matches ? 'dark' : 'light';
            document.documentElement.setAttribute('data-theme', newTheme);
        }
    });
}

// Функція для зміни мови інтерфейсу на основі обраної теми
function updateUIBasedOnTheme(theme) {
    // Оновлюємо кнопки теми
    const themeSwitchButtons = document.querySelectorAll('.theme-switch-btn, #toggleThemeButton');
    themeSwitchButtons.forEach(button => {
        const icon = button.querySelector('i');
        if (icon) {
            icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
        }

        // Якщо це кнопка в меню, оновлюємо також текст
        if (button.id === 'toggleThemeButton') {
            button.textContent = ' ' + (theme === 'dark' ? 'Світла тема' : 'Темна тема');
            button.insertBefore(icon.cloneNode(true), button.firstChild);
        }
    });
}

// Список елементів, які потрібно адаптувати під темну тему
const themeAwareElements = [
    '.comments-tabs .tab-item',
    '.filter-btn',
    '.btn-read',
    '.btn-view',
    '.view-more-btn',
    '.page-item'
];

// Застосовуємо початкову тему при завантаженні сторінки
document.addEventListener('DOMContentLoaded', function() {
    const currentTheme = getThemePreference();
    document.documentElement.setAttribute('data-theme', currentTheme);
    updateUIBasedOnTheme(currentTheme);
});

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
            const isCollapsed = mainWrapper.classList.contains('sidebar-collapsed');
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

        // Оновлюємо текст та іконку кнопки в меню
        const toggleThemeButton = document.getElementById('toggleThemeButton');
        if (toggleThemeButton) {
            const icon = toggleThemeButton.querySelector('i');
            if (icon) {
                icon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
            }
            toggleThemeButton.innerHTML = '';
            const newIcon = document.createElement('i');
            newIcon.className = theme === 'dark' ? 'bi bi-sun' : 'bi bi-moon';
            toggleThemeButton.appendChild(newIcon);
            toggleThemeButton.appendChild(document.createTextNode(' ' + (theme === 'dark' ? 'Світла тема' : 'Темна тема')));
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
        const currentTheme = htmlElement.getAttribute('data-theme') || 'dark';
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

    // Додаємо обробник для перемикача теми в меню користувача
    const toggleThemeButton = document.getElementById('toggleThemeButton');
    if (toggleThemeButton) {
        toggleThemeButton.addEventListener('click', function(e) {
            e.preventDefault();
            toggleTheme();

            // Закриваємо меню користувача
            if (userDropdownMenu) {
                userDropdownMenu.classList.remove('show');
            }
        });
    }

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

    // Застосовуємо поточну тему при завантаженні сторінки
    const currentTheme = document.documentElement.getAttribute('data-theme');
    if (currentTheme) {
        applyTheme(currentTheme);
    }

    // Функції для роботи з Toast-сповіщеннями
    function showToast(message, type) {
        const toastContainer = document.getElementById('toast-container');
        if (!toastContainer) return;

        const toast = document.createElement('div');
        toast.className = `toast ${type}`;
        toast.innerHTML = `
            <div class="toast-content">
                <i class="bi ${type === 'success' ? 'bi-check-circle' : 'bi-exclamation-triangle'}"></i>
                <span>${message}</span>
            </div>
            <i class="bi bi-x toast-close"></i>
        `;

        toastContainer.appendChild(toast);

        // Показуємо Toast
        setTimeout(() => {
            toast.classList.add('show');
        }, 10);

        // Автоматично ховаємо через 5 секунд
        setTimeout(() => {
            toast.classList.remove('show');
            setTimeout(() => {
                toastContainer.removeChild(toast);
            }, 300);
        }, 5000);

        // Обробник для кнопки закриття
        const closeBtn = toast.querySelector('.toast-close');
        if (closeBtn) {
            closeBtn.addEventListener('click', () => {
                toast.classList.remove('show');
                setTimeout(() => {
                    toastContainer.removeChild(toast);
                }, 300);
            });
        }
    }

    // Показуємо Toast при успішній дії, якщо є відповідний параметр в URL
    const urlParams = new URLSearchParams(window.location.search);
    if (urlParams.has('success')) {
        const successType = urlParams.get('success');

        if (successType === 'marked_read') {
            showToast('Коментар успішно позначено як прочитаний', 'success');
        } else if (successType === 'all_marked_read') {
            showToast('Всі коментарі позначені як прочитані', 'success');
        }
    }

    if (urlParams.has('error')) {
        const errorType = urlParams.get('error');

        if (errorType === 'not_found') {
            showToast('Коментар не знайдено', 'error');
        } else if (errorType === 'db_error') {
            showToast('Помилка бази даних. Спробуйте пізніше.', 'error');
        }
    }

    // Функціонал пошуку коментарів
    const searchInput = document.getElementById('comment-search');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase().trim();
            const commentItems = document.querySelectorAll('.comment-item');

            commentItems.forEach(item => {
                const commentText = item.querySelector('.comment-text').textContent.toLowerCase();
                const commentService = item.querySelector('.comment-service-link').textContent.toLowerCase();
                const commentAuthor = item.querySelector('.author-name').textContent.toLowerCase();

                const isVisible =
                    commentText.includes(searchTerm) ||
                    commentService.includes(searchTerm) ||
                    commentAuthor.includes(searchTerm);

                item.style.display = isVisible ? 'block' : 'none';
            });

            // Якщо немає видимих коментарів, показуємо повідомлення
            const visibleComments = Array.from(commentItems).filter(item => item.style.display !== 'none');
            const commentsList = document.querySelector('.comments-list');
            let noResultsElement = document.querySelector('.no-search-results');

            if (commentsList && visibleComments.length === 0 && searchTerm.length > 0) {
                if (!noResultsElement) {
                    noResultsElement = document.createElement('div');
                    noResultsElement.className = 'no-search-results';
                    noResultsElement.innerHTML = `
                        <i class="bi bi-search"></i>
                        <p>Немає результатів за запитом "${searchTerm}"</p>
                    `;
                    commentsList.appendChild(noResultsElement);
                } else {
                    noResultsElement.querySelector('p').textContent = `Немає результатів за запитом "${searchTerm}"`;
                    noResultsElement.style.display = 'flex';
                }
            } else if (noResultsElement) {
                noResultsElement.style.display = 'none';
            }
        });
    }

    // Функція для застосування активного стану кнопці фільтру при кліку
    const filterButtons = document.querySelectorAll('.filter-btn');
    filterButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Додаємо клас active лише при навігації через кнопки
            if (!this.classList.contains('btn-clear')) {
                filterButtons.forEach(btn => btn.classList.remove('active'));
                this.classList.add('active');
            }
        });
    });

    // Кнопки для позначення коментарів прочитаними
    const markReadButtons = document.querySelectorAll('.btn-read');
    markReadButtons.forEach(button => {
        button.addEventListener('click', function() {
            // Предотвращаем множественные клики
            if (this.classList.contains('processing')) {
                return;
            }

            this.classList.add('processing');
            this.innerHTML = '<i class="bi bi-hourglass"></i> Обробка...';
        });
    });

    // Функціонал кнопки "Позначити всі прочитаними"
    const markAllReadButton = document.querySelector('a.filter-btn.btn-clear');
    if (markAllReadButton) {
        markAllReadButton.addEventListener('click', function() {
            // Запобігаємо множинним кликам
            if (this.classList.contains('processing')) {
                return;
            }

            this.classList.add('processing');
            this.innerHTML = '<i class="bi bi-hourglass"></i> Обробка...';
        });
    }
});