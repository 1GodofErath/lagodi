/**
 * Модуль для перегляду медіа-файлів
 * Підтримує зображення, відео, аудіо, PDF та текстові файли
 */
const MediaViewer = {
    // Конфігурація
    config: {
        modalId: 'media-modal',
        zoomLevels: [1, 1.25, 1.5, 2, 3],
        currentZoomIndex: 0,
        fileTypes: {
            image: ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg'],
            video: ['mp4', 'webm', 'ogg', 'mov', 'avi', 'mkv', 'flv'],
            audio: ['mp3', 'wav', 'ogg', 'aac', 'flac', 'm4a'],
            pdf: ['pdf'],
            text: ['txt', 'log', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js']
        }
    },

    // Дані про поточний файл і стан
    state: {
        files: [],
        currentIndex: 0,
        isLoading: false,
        modalCreated: false
    },

    /**
     * Ініціалізація модуля
     */
    init: function() {
        if (!this.state.modalCreated) {
            this.createModal();
            this.bindEvents();
            this.state.modalCreated = true;
        }
    },

    /**
     * Створення структури модального вікна для перегляду медіа
     */
    createModal: function() {
        // Створюємо модальне вікно, якщо воно ще не існує
        let modal = document.getElementById(this.config.modalId);

        if (!modal) {
            modal = document.createElement('div');
            modal.id = this.config.modalId;
            modal.className = 'media-modal';

            modal.innerHTML = `
                <div class="media-container">
                    <div class="media-header">
                        <h3 class="media-title"></h3>
                        <button type="button" class="media-close">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                    <div class="media-content">
                        <div class="loading-spinner"></div>
                    </div>
                    <div class="media-footer">
                        <div class="media-nav">
                            <button type="button" class="media-nav-btn prev-btn" disabled>
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <span class="media-counter">0/0</span>
                            <button type="button" class="media-nav-btn next-btn" disabled>
                                <i class="fas fa-chevron-right"></i>
                            </button>
                        </div>
                        <a href="#" class="media-download" download>
                            <i class="fas fa-download"></i> Завантажити
                        </a>
                    </div>
                </div>
            `;

            document.body.appendChild(modal);
        }
    },

    /**
     * Прив'язка обробників подій
     */
    bindEvents: function() {
        const self = this;
        const modal = document.getElementById(this.config.modalId);

        if (!modal) return;

        // Закриття модального вікна
        modal.querySelector('.media-close').addEventListener('click', function() {
            self.close();
        });

        modal.addEventListener('click', function(e) {
            if (e.target === modal) {
                self.close();
            }
        });

        // Навігаційні кнопки
        modal.querySelector('.prev-btn').addEventListener('click', function() {
            if (!this.disabled) {
                self.showPrev();
            }
        });

        modal.querySelector('.next-btn').addEventListener('click', function() {
            if (!this.disabled) {
                self.showNext();
            }
        });

        // Клавіатурна навігація
        document.addEventListener('keydown', function(e) {
            if (!modal.classList.contains('active')) return;

            switch (e.key) {
                case 'Escape':
                    self.close();
                    break;
                case 'ArrowLeft':
                    self.showPrev();
                    break;
                case 'ArrowRight':
                    self.showNext();
                    break;
                case '+':
                case '=':
                    self.zoomIn();
                    break;
                case '-':
                    self.zoomOut();
                    break;
            }
        });
    },

    /**
     * Відкриття переглядача медіа для одного файлу
     * @param {string} filePath - Шлях до файлу
     * @param {string} fileName - Назва файлу
     */
    open: function(filePath, fileName) {
        this.init();

        this.state.files = [{ path: filePath, name: fileName }];
        this.state.currentIndex = 0;

        this.openModal();
        this.showCurrent();
    },

    /**
     * Відкриття переглядача медіа для групи файлів
     * @param {Array} files - Масив об'єктів з полями path і name
     * @param {number} startIndex - Початковий індекс файлу для перегляду
     */
    openGallery: function(files, startIndex = 0) {
        this.init();

        if (!files || !files.length) return;

        this.state.files = files;
        this.state.currentIndex = Math.min(Math.max(0, startIndex), files.length - 1);

        this.openModal();
        this.showCurrent();
    },

    /**
     * Відкриття модального вікна
     */
    openModal: function() {
        const modal = document.getElementById(this.config.modalId);
        if (!modal) return;

        modal.classList.add('active');
        document.body.style.overflow = 'hidden';
    },

/**
 *    /**
 *      * Закриття модального вікна
 *      */
    *     close: function () {
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *
        modal.classList.remove('active');
    *
        document.body.style.overflow = '';
    *
    *         // Скидання стану
    *
        this.resetZoom();
    *
        this.state.isLoading = false;
    *
    },
    *
    *     /**
     *      * Показ поточного файлу
     *      */
    *     showCurrent: function () {
    *
        if (!this.state.files.length) return;
    *
    *
        const currentFile = this.state.files[this.state.currentIndex];
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *         // Оновлення заголовка
    *
        modal.querySelector('.media-title').textContent = currentFile.name;
    *
    *         // Оновлення посилання для завантаження
    *
        const downloadLink = modal.querySelector('.media-download');
    *
        downloadLink.href = currentFile.path;
    *
        downloadLink.setAttribute('download', currentFile.name);
    *
    *         // Оновлення лічильника
    *
        modal.querySelector('.media-counter').textContent = `${this.state.currentIndex + 1}/${this.state.files.length}`;
    *
    *         // Оновлення стану навігаційних кнопок
    *
        const prevBtn = modal.querySelector('.prev-btn');
    *
        const nextBtn = modal.querySelector('.next-btn');
    *
    *
        prevBtn.disabled = this.state.currentIndex === 0;
    *
        nextBtn.disabled = this.state.currentIndex === this.state.files.length - 1;
    *
    *         // Відображення вмісту
    *
        this.loadContent(currentFile);
    *
    },
    *
    *     /**
     *      * Завантаження та відображення вмісту файлу
     *      * @param {Object} file - Об'єкт з інформацією про файл
     *      */
    *     loadContent: function (file) {
    *
        if (!file) return;
    *
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *
        const contentContainer = modal.querySelector('.media-content');
    *
    *         // Показуємо індикатор завантаження
    *
        contentContainer.innerHTML = '<div class="loading-spinner"></div>';
    *
        this.state.isLoading = true;
    *
    *         // Визначаємо тип файлу за розширенням
    *
        const fileExt = this.getFileExtension(file.name);
    *
        const fileType = this.getFileTypeByExtension(fileExt);
    *
    *         // Завантажуємо вміст відповідно до типу файлу
    *
        switch (fileType) {
            *
            case 'image':
            *
                this.loadImage(file, contentContainer);
            *
                break;
            *
            case 'video':
            *
                this.loadVideo(file, contentContainer);
            *
                break;
            *
            case 'audio':
            *
                this.loadAudio(file, contentContainer);
            *
                break;
            *
            case 'pdf':
            *
                this.loadPDF(file, contentContainer);
            *
                break;
            *
            case 'text':
            *
                this.loadText(file, contentContainer);
            *
                break;
            *
            default:
            *
                this.showUnsupported(contentContainer);
            *
                break;
            *
        }
    *
    },
    *
    *     /**
     *      * Завантаження та відображення зображення
     *      * @param {Object} file - Об'єкт файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     loadImage: function (file, container) {
    *
        const self = this;
    *
        const img = new Image();
    *
    *
        img.onload = function () {
        *
            container.innerHTML = '';
        *
            container.appendChild(img);
        *
        *
            img.className = 'media-image';
        *
        *             // Додаємо елементи керування масштабом для зображень
        *
            self.addZoomControls(container);
        *
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        img.onerror = function () {
        *
            self.showError(container, 'Помилка завантаження зображення');
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        img.src = file.path;
    *
    },
    *
    *     /**
     *      * Завантаження та відображення відео
     *      * @param {Object} file - Об'єкт файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     loadVideo: function (file, container) {
    *
        const self = this;
    *
        const video = document.createElement('video');
    *
    *
        video.className = 'media-video';
    *
        video.controls = true;
    *
        video.autoplay = false;
    *
        video.preload = 'metadata';
    *
    *
        video.onloadedmetadata = function () {
        *
            container.innerHTML = '';
        *
            container.appendChild(video);
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        video.onerror = function () {
        *
            self.showError(container, 'Помилка завантаження відео');
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        video.src = file.path;
    *
    },
    *
    *     /**
     *      * Завантаження та відображення аудіо
     *      * @param {Object} file - Об'єкт файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     loadAudio: function (file, container) {
    *
        const self = this;
    *
        const audio = document.createElement('audio');
    *
    *
        audio.className = 'media-audio';
    *
        audio.controls = true;
    *
        audio.autoplay = false;
    *
    *
        audio.onloadedmetadata = function () {
        *
            container.innerHTML = '';
        *
            container.appendChild(audio);
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        audio.onerror = function () {
        *
            self.showError(container, 'Помилка завантаження аудіо');
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        audio.src = file.path;
    *
    },
    *
    *     /**
     *      * Завантаження та відображення PDF
     *      * @param {Object} file - Об'єкт файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     loadPDF: function (file, container) {
    *
        const self = this;
    *
        const iframe = document.createElement('iframe');
    *
    *
        iframe.className = 'media-pdf';
    *
        iframe.src = file.path;
    *
    *
        iframe.onload = function () {
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        iframe.onerror = function () {
        *
            self.showError(container, 'Помилка завантаження PDF');
        *
            self.state.isLoading = false;
        *
        };
    *
    *
        container.innerHTML = '';
    *
        container.appendChild(iframe);
    *
    },
    *
    *     /**
     *      * Завантаження та відображення текстового файлу
     *      * @param {Object} file - Об'єкт файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     loadText: function (file, container) {
    *
        const self = this;
    *
    *
        fetch(file.path)
        *
    .
        then(response => {
        *
            if (!response.ok) {
            *
                throw new Error('Помилка завантаження файлу');
            *
            }
        *
            return response.text();
        *
        })
        *
    .
        then(text => {
        *
            container.innerHTML = '';
        *
        *
            const pre = document.createElement('pre');
        *
            pre.className = 'media-text';
        *
            pre.textContent = text;
        *
        *
            container.appendChild(pre);
        *
            self.state.isLoading = false;
        *
        })
        *
    .
        catch(error => {
        *
            self.showError(container, error.message);
        *
            self.state.isLoading = false;
        *
        });
    *
    },
    *
    *     /**
     *      * Відображення повідомлення про непідтримуваний тип файлу
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      */
    *     showUnsupported: function (container) {
    *
        container.innerHTML = `
 *             <div class="empty-file">
 *                 <i class="fas fa-file"></i>
 *                 <h3>Файл недоступний для перегляду</h3>
 *                 <p>Тип файлу не підтримується для перегляду. Використайте кнопку "Завантажити".</p>
 *             </div>
 *         `;
    *
        this.state.isLoading = false;
    *
    },
    *
    *     /**
     *      * Відображення повідомлення про помилку
     *      * @param {HTMLElement} container - Контейнер для вмісту
     *      * @param {string} message - Повідомлення про помилку
     *      */
    *     showError: function (container, message) {
    *
        container.innerHTML = `
 *             <div class="empty-file">
 *                 <i class="fas fa-exclamation-circle"></i>
 *                 <h3>Помилка завантаження</h3>
 *                 <p>${message}</p>
 *             </div>
 *         `;
    *
    },
    *
    *     /**
     *      * Додавання елементів керування масштабом для зображень
     *      * @param {HTMLElement} container - Контейнер з вмістом
     *      */
    *     addZoomControls: function (container) {
    *
        const self = this;
    *
        const zoomControls = document.createElement('div');
    *
        zoomControls.className = 'media-zoom-controls';
    *
        zoomControls.innerHTML = `
 *             <button class="zoom-btn zoom-out" title="Зменшити">
 *                 <i class="fas fa-search-minus"></i>
 *             </button>
 *             <button class="zoom-btn zoom-in" title="Збільшити">
 *                 <i class="fas fa-search-plus"></i>
 *             </button>
 *         `;
    *
    *
        const zoomLevel = document.createElement('div');
    *
        zoomLevel.className = 'zoom-level';
    *
        zoomLevel.textContent = '100%';
    *
    *
        container.appendChild(zoomControls);
    *
        container.appendChild(zoomLevel);
    *
    *         // Обробники кнопок масштабування
    *
        zoomControls.querySelector('.zoom-in').addEventListener('click', function () {
        *
            self.zoomIn();
        *
        });
    *
    *
        zoomControls.querySelector('.zoom-out').addEventListener('click', function () {
        *
            self.zoomOut();
        *
        });
    *
    },
    *
    *     /**
     *      * Збільшення масштабу
     *      */
    *     zoomIn: function () {
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *
        const image = modal.querySelector('.media-image');
    *
        const zoomLevel = modal.querySelector('.zoom-level');
    *
    *
        if (!image || !zoomLevel) return;
    *
    *
        if (this.config.currentZoomIndex < this.config.zoomLevels.length - 1) {
        *
            this.config.currentZoomIndex++;
        *
            const scale = this.config.zoomLevels[this.config.currentZoomIndex];
        *
        *
            image.style.transform = `scale(${scale})`;
        *
            zoomLevel.textContent = `${Math.round(scale * 100)}%`;
        *
        }
    *
    },
    *
    *     /**
     *      * Зменшення масштабу
     *      */
    *     zoomOut: function () {
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *
        const image = modal.querySelector('.media-image');
    *
        const zoomLevel = modal.querySelector('.zoom-level');
    *
    *
        if (!image || !zoomLevel) return;
    *
    *
        if (this.config.currentZoomIndex > 0) {
        *
            this.config.currentZoomIndex--;
        *
            const scale = this.config.zoomLevels[this.config.currentZoomIndex];
        *
        *
            image.style.transform = `scale(${scale})`;
        *
            zoomLevel.textContent = `${Math.round(scale * 100)}%`;
        *
        }
    *
    },
    *
    *     /**
     *      * Скидання масштабу до початкового значення
     *      */
    *     resetZoom: function () {
    *
        this.config.currentZoomIndex = 0;
    *
    *
        const modal = document.getElementById(this.config.modalId);
    *
        if (!modal) return;
    *
    *
        const image = modal.querySelector('.media-image');
    *
        const zoomLevel = modal.querySelector('.zoom-level');
    *
    *
        if (image) {
        *
            image.style.transform = '';
        *
        }
    *
    *
        if (zoomLevel) {
        *
            zoomLevel.textContent = '100%';
        *
        }
    *
    },
    *
    *     /**
     *      * Перехід до наступного файлу
     *      */
    *     showNext: function () {
    *
        if (this.state.isLoading || this.state.currentIndex >= this.state.files.length - 1) return;
    *
    *
        this.state.currentIndex++;
    *
        this.resetZoom();
    *
        this.showCurrent();
    *
    },
    *
    *     /**
     *      * Перехід до попереднього файлу
     *      */
    *     showPrev: function () {
    *
        if (this.state.isLoading || this.state.currentIndex <= 0) return;
    *
    *
        this.state.currentIndex--;
    *
        this.resetZoom();
    *
        this.showCurrent();
    *
    },
    *
    *     /**
     *      * Отримання розширення файлу з імені
     *      * @param {string} filename - Ім'я файлу
     *      * @returns {string} Розширення файлу
     *      */
    *     getFileExtension: function (filename) {
    *
        if (!filename) return '';
    *
    *
        return filename.split('.').pop().toLowerCase();
    *
    },
    *
    *     /**
     *      * Визначення типу файлу за розширенням
     *      * @param {string} extension - Розширення файлу
     *      * @returns {string} Тип файлу
     *      */
    *     getFileTypeByExtension: function (extension) {
        *
            if (!extension) return 'other';
        *
        *
            for (const [type, extensions] of Object.entries(this.config.fileTypes)) {
            *
                if (extensions.includes(extension.toLowerCase())) {
                *
                    return type;
                *
                }
            *
            }
        *
        *
            return 'other';
        *
        }
        *
};
*
* // Автоматична ініціалізація модуля при завантаженні сторінки
*
document.addEventListener('DOMContentLoaded', function () {
*
    MediaViewer.init();
*
});