// Theme handling
function setTheme(theme) {
    document.documentElement.setAttribute('data-theme', theme);
    localStorage.setItem('theme', theme);

    fetch('/dash_panel/profile_handler.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
        },
        body: JSON.stringify({
            action: 'update_profile',
            theme_preference: theme
        })
    });
}

document.getElementById('theme-toggle').addEventListener('change', function() {
    setTheme(this.checked ? 'dark' : 'light');
});

// Modal handling
function showModal(title, content, size = '') {
    const modalId = 'dynamicModal';
    let modalElement = document.getElementById(modalId);

    if (modalElement) {
        modalElement.remove();
    }

    const modalHTML = `
        <div class="modal fade" id="${modalId}" tabindex="-1">
            <div class="modal-dialog ${size}">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">${title}</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        ${content}
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHTML);
    modalElement = document.getElementById(modalId);

    const modal = new bootstrap.Modal(modalElement);
    modal.show();

    modalElement.addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// File preview handling
function previewFile(file, container) {
    const reader = new FileReader();
    const preview = document.createElement('div');
    preview.className = 'file-preview-item mb-2';

    reader.onload = function(e) {
        if (file.type.startsWith('image/')) {
            preview.innerHTML = `
                <div class="d-flex align-items-center">
                    <img src="${e.target.result}" class="img-thumbnail" style="max-height: 100px">
                    <span class="ms-2">${file.name}</span>
                    <button type="button" class="btn btn-sm btn-danger ms-auto remove-file">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        } else {
            preview.innerHTML = `
                <div class="d-flex align-items-center">
                    <i class="fas fa-file fa-2x"></i>
                    <span class="ms-2">${file.name}</span>
                    <button type="button" class="btn btn-sm btn-danger ms-auto remove-file">
                        <i class="fas fa-times"></i>
                    </button>
                </div>
            `;
        }

        preview.querySelector('.remove-file').addEventListener('click', function() {
            preview.remove();
        });
    }

    reader.readAsDataURL(file);
    container.appendChild(preview);
}

// Date and time handling
function updateDateTime() {
    const now = new Date();
    const options = {
        year: 'numeric',
        month: '2-digit',
        day: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
        second: '2-digit',
        hour12: false
    };

    const timeString = now.toLocaleString('uk-UA', options)
        .replace(',', '')
        .replace(/\./g, '-');

    document.querySelectorAll('.current-time').forEach(element => {
        element.textContent = timeString;
    });
}

setInterval(updateDateTime, 1000);
updateDateTime();

// Sidebar handling
document.getElementById('sidebarCollapse')?.addEventListener('click', function() {
    const sidebar = document.getElementById('sidebar');
    if (sidebar) {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebar_collapsed', sidebar.classList.contains('collapsed'));
    }
});

// Initialize tooltips
document.addEventListener('DOMContentLoaded', function() {
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    tooltipTriggerList.forEach(function(tooltipTriggerEl) {
        new bootstrap.Tooltip(tooltipTriggerEl);
    });
});