document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const mainContent = document.getElementById('mainContent');
    const mainWrapper = document.getElementById('mainWrapper');
    const menuToggle = document.getElementById('menuToggle');
    const sidebarToggle = document.getElementById('sidebarToggle');
    const overlay = document.getElementById('overlay');
    const themeSwitchBtn = document.getElementById('themeSwitchBtn');
    const toggleThemeButton = document.getElementById('toggleThemeButton');
    const fileInput = document.getElementById('attachments');
    const fileList = document.getElementById('fileList');

    // Додамо обробку категорій
    const categorySelect = document.getElementById('category_id');

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

    // Функція для створення випадаючого списку категорій
    function setupCategoryDropdown() {
        // Додаємо стрілку до селекту
        const categoryContainer = document.querySelector('.category-select-container');
        if (!categoryContainer || !categorySelect) return;

        categorySelect.style.backgroundImage = 'none';

        // Створюємо власну стрілку
        const customArrow = document.createElement('div');
        customArrow.className = 'custom-select-arrow';
        customArrow.innerHTML = '<i class="bi bi-chevron-down"></i>';
        categoryContainer.appendChild(customArrow);
    }

    // Налаштування випадаючого списку категорій
    setupCategoryDropdown();

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

    // Обробка вибору файлів
    if (fileInput) {
        fileInput.addEventListener('change', function() {
            if (this.files.length > 0) {
                fileList.innerHTML = '';

                // Показуємо обмеження на кількість файлів
                if (this.files.length > 5) {
                    showToast('error', 'Можна вибрати не більше 5 файлів', 'Помилка');
                    this.value = ''; // Скидаємо вибрані файли
                    fileList.textContent = 'Файли не вибрано';
                    return;
                }

                // Перевірка на розмір файлів
                let hasInvalidSize = false;
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const sizeMB = file.size / (1024 * 1024);

                    if (sizeMB > 10) {
                        hasInvalidSize = true;
                        break;
                    }
                }

                if (hasInvalidSize) {
                    showToast('error', 'Розмір файлу не може перевищувати 10MB', 'Помилка');
                    this.value = ''; // Скидаємо вибрані файли
                    fileList.textContent = 'Файли не вибрано';
                    return;
                }

                // Відображення списку вибраних файлів
                for (let i = 0; i < this.files.length; i++) {
                    const file = this.files[i];
                    const sizeMB = (file.size / (1024 * 1024)).toFixed(2);

                    const fileItem = document.createElement('div');
                    fileItem.className = 'file-item';

                    let fileIcon = 'bi-file-earmark';

                    // Визначаємо тип файлу та відповідну іконку
                    if (file.type.includes('image')) {
                        fileIcon = 'bi-file-earmark-image';
                    } else if (file.type.includes('pdf')) {
                        fileIcon = 'bi-file-earmark-pdf';
                    } else if (file.type.includes('word') || file.name.endsWith('.doc') || file.name.endsWith('.docx')) {
                        fileIcon = 'bi-file-earmark-word';
                    }

                    fileItem.innerHTML = `
                        <div class="file-name">
                            <i class="bi ${fileIcon}"></i>
                            ${file.name} (${sizeMB} MB)
                        </div>
                        <button type="button" class="remove-file" data-index="${i}">
                            <i class="bi bi-x-circle"></i>
                        </button>
                    `;

                    fileList.appendChild(fileItem);
                }

                // Додаємо обробники для кнопок видалення файлів
                document.querySelectorAll('.remove-file').forEach(button => {
                    button.addEventListener('click', function() {
                        // Неможливо видалити окремий файл з FileList, тому створюємо новий FileList
                        const dt = new DataTransfer();
                        const files = fileInput.files;
                        const index = parseInt(this.dataset.index);

                        for (let i = 0; i < files.length; i++) {
                            if (i !== index) {
                                dt.items.add(files[i]);
                            }
                        }

                        fileInput.files = dt.files;

                        // Видаляємо елемент з DOM
                        this.closest('.file-item').remove();
                        // Оновлюємо відображення, якщо файлів не залишилось
                        if (fileInput.files.length === 0) {
                            fileList.textContent = 'Файли не вибрано';
                        } else {
                            // Оновлюємо індекси для коректного видалення
                            document.querySelectorAll('.remove-file').forEach((btn, idx) => {
                                btn.dataset.index = idx;
                            });
                        }
                    });
                });

            } else {
                fileList.textContent = 'Файли не вибрано';
            }
        });
    }

    // Валідація форми перед відправкою
    const orderForm = document.getElementById('createOrderForm');
    if (orderForm) {
        orderForm.addEventListener('submit', function(e) {
            let hasErrors = false;
            const requiredFields = document.querySelectorAll('[required]');

            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.style.borderColor = '#dc3545';
                    hasErrors = true;
                } else {
                    field.style.borderColor = '';
                }
            });

            // Перевірка формату телефону
            const phoneField = document.getElementById('phone');
            if (phoneField && phoneField.value) {
                const phoneRegex = /^[0-9+\-\s()]{10,15}$/;
                if (!phoneRegex.test(phoneField.value)) {
                    phoneField.style.borderColor = '#dc3545';
                    hasErrors = true;

                    if (!document.querySelector('.phone-error')) {
                        const errorMsg = document.createElement('div');
                        errorMsg.className = 'error-message phone-error';
                        errorMsg.textContent = 'Невірний формат номера телефону';
                        errorMsg.style.color = '#dc3545';
                        errorMsg.style.fontSize = '0.85rem';
                        errorMsg.style.marginTop = '5px';

                        phoneField.parentNode.appendChild(errorMsg);
                    }
                } else {
                    const errorMsg = document.querySelector('.phone-error');
                    if (errorMsg) {
                        errorMsg.remove();
                    }
                }
            }

            if (hasErrors) {
                e.preventDefault();
                showToast('error', 'Будь ласка, заповніть всі обов\'язкові поля коректно', 'Помилка');

                // Прокручуємо до першого поля з помилкою
                const firstErrorField = document.querySelector('[style*="border-color: #dc3545"]');
                if (firstErrorField) {
                    firstErrorField.focus();
                    firstErrorField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            } else {
                // Показуємо індикатор завантаження
                const submitButton = document.querySelector('button[type="submit"]');
                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML = '<i class="bi bi-hourglass"></i> Створення замовлення...';
                }
            }
        });

        // Видаляємо помилки при введенні
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('input', function() {
                this.style.borderColor = '';
                const errorMsg = this.parentNode.querySelector('.error-message');
                if (errorMsg) {
                    errorMsg.remove();
                }
            });
        });
    }

    // Функція для автозаповнення поля пристрою на основі категорії
    function setupDeviceAutofill() {
        if (categorySelect) {
            categorySelect.addEventListener('change', function() {
                const deviceTypeField = document.getElementById('device_type');
                if (!deviceTypeField || deviceTypeField.value) return; // Не змінюємо, якщо вже заповнено

                const selectedOption = this.options[this.selectedIndex];
                const categoryName = selectedOption.textContent.trim().toLowerCase();

                // Автозаповнення типу пристрою на основі категорії
                if (categoryName.includes('ноутбук')) {
                    deviceTypeField.value = 'Ноутбук';
                } else if (categoryName.includes('комп\'ютер')) {
                    deviceTypeField.value = 'Стаціонарний комп\'ютер';
                } else if (categoryName.includes('смартфон')) {
                    deviceTypeField.value = 'Смартфон';
                } else if (categoryName.includes('планшет')) {
                    deviceTypeField.value = 'Планшет';
                }
            });
        }
    }

    // Налаштовуємо автозаповнення, якщо форма присутня
    if (document.getElementById('createOrderForm')) {
        setupDeviceAutofill();
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
});