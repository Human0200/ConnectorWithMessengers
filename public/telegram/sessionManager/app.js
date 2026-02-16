// Конфигурация
const API_URL = 'api.php'; // Теперь указываем ваш файл session_manager.php

// DOM элементы
const domainText = document.getElementById('domainText');
const domainHidden = document.getElementById('domainHidden');
const domainSpinner = document.querySelector('.domain-loading');
const sessionsContainer = document.getElementById('sessionsContainer');
const sessionsList = document.getElementById('sessionsList');
const addSessionBtn = document.getElementById('addSessionBtn');
const createSessionModal = document.getElementById('createSessionModal');
const createSessionForm = document.getElementById('createSessionForm');
const modalDomain = document.getElementById('modalDomain');
const cancelBtn = document.getElementById('cancelBtn');
const successAlert = document.getElementById('successAlert');
const errorAlert = document.getElementById('errorAlert');

let currentDomain = '';

// Показать уведомление
function showAlert(message, type = 'success') {
    const alert = type === 'success' ? successAlert : errorAlert;
    alert.textContent = message;
    alert.classList.add('active');

    setTimeout(() => {
        alert.classList.remove('active');
    }, 5000);
}

// Загрузка сессий
async function loadSessions(domain) {
    sessionsList.innerHTML = '<div class="loading"><div class="spinner"></div><p>Загрузка сессий...</p></div>';
    sessionsContainer.classList.add('active');

    try {
        const formData = new FormData();
        formData.append('action', 'get_sessions');
        formData.append('domain', domain);

        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            displaySessions(data.sessions);
        } else {
            throw new Error(data.error || 'Ошибка загрузки');
        }
    } catch (error) {
        sessionsList.innerHTML = `<div class="empty-state"><h2>Ошибка</h2><p>${error.message}</p></div>`;
    }
}

// Отображение сессий
function displaySessions(sessions) {
    if (!sessions || sessions.length === 0) {
        sessionsList.innerHTML = `
            <div class="empty-state">
                <svg fill="currentColor" viewBox="0 0 20 20">
                    <path d="M10 2a8 8 0 100 16 8 8 0 000-16zM9 9a1 1 0 012 0v4a1 1 0 11-2 0V9zm1-5a1 1 0 100 2 1 1 0 000-2z"/>
                </svg>
                <h2>Нет активных сессий</h2>
                <p>Создайте первую сессию для начала работы</p>
            </div>
        `;
        return;
    }

    sessionsList.innerHTML = sessions.map(session => {
        // Проверяем структуру данных, которую возвращает ваш API
        const sessionId = session.session_id || session.id;
        const sessionName = session.session_name || session.name;
        const status = session.status || 'pending';
        const accountFirstName = session.account_first_name || session.first_name;
        const accountUsername = session.account_username || session.username;
        
        const isAuthorized = status === 'authorized';
        const statusClass = isAuthorized ? 'authorized' : 'pending';
        const statusText = isAuthorized ? '✓ Авторизован' : '⏳ Ожидает авторизации';

        return `
            <div class="session-item">
                <div class="session-info">
                    <h3>${sessionName}</h3>
                    <p>ID: ${sessionId}</p>
                    ${accountFirstName ? `
                        <p>Аккаунт: ${accountFirstName} ${accountUsername ? '@' + accountUsername : ''}</p>
                    ` : ''}
                    <span class="session-status ${statusClass}">
                        ${statusText}
                    </span>
                </div>
                <div class="session-actions">
                    ${!isAuthorized ? `
                        <a href="qr_auth.php?session_id=${sessionId}&domain=${currentDomain}" class="btn btn-success">
                            Авторизовать
                        </a>
                    ` : ''}
                    <button class="btn btn-danger" onclick="deleteSession('${sessionId}')">
                        Удалить
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

// Удаление сессии
async function deleteSession(sessionId) {
    if (!confirm('Вы уверены, что хотите удалить эту сессию?')) {
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'delete_session');
        formData.append('session_id', sessionId);
        formData.append('domain', currentDomain);

        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showAlert(data.message || 'Сессия успешно удалена', 'success');
            loadSessions(currentDomain);
        } else {
            throw new Error(data.error);
        }
    } catch (error) {
        showAlert('Ошибка удаления: ' + error.message, 'error');
    }
}

// Глобальная функция для onclick
window.deleteSession = deleteSession;

// События
addSessionBtn.addEventListener('click', () => {
    modalDomain.value = currentDomain;
    createSessionModal.classList.add('active');
});

cancelBtn.addEventListener('click', () => {
    createSessionModal.classList.remove('active');
    createSessionForm.reset();
});

createSessionForm.addEventListener('submit', async (e) => {
    e.preventDefault();

    const sessionName = document.getElementById('sessionName').value;
    const domain = modalDomain.value;

    if (!sessionName.trim()) {
        showAlert('Введите название сессии', 'error');
        return;
    }

    try {
        const formData = new FormData();
        formData.append('action', 'create_session');
        formData.append('domain', domain);
        formData.append('session_name', sessionName);

        const response = await fetch(API_URL, {
            method: 'POST',
            body: formData
        });

        const data = await response.json();

        if (data.success) {
            showAlert(data.message, 'success');
            createSessionModal.classList.remove('active');
            createSessionForm.reset();

            // Перенаправляем на авторизацию
            setTimeout(() => {
                window.location.href = `qr_auth.php?session_id=${data.session_id}&domain=${domain}`;
            }, 1000);
        } else {
            throw new Error(data.error);
        }
    } catch (error) {
        showAlert('Ошибка создания: ' + error.message, 'error');
    }
});

// Закрытие модального окна по клику вне его
createSessionModal.addEventListener('click', (e) => {
    if (e.target === createSessionModal) {
        createSessionModal.classList.remove('active');
        createSessionForm.reset();
    }
});

// Получение домена через BX24 API
function initBitrix24() {
    if (typeof BX24 !== 'undefined' && BX24.init) {
        BX24.init(function() {
            console.log('BX24 initialized');
            const domain = BX24.getDomain();
            domainText.textContent = domain;
            domainHidden.value = domain;
            currentDomain = domain;
            domainSpinner.style.display = 'none';
            addSessionBtn.disabled = false;
            // Загружаем сессии для этого домена
            loadSessions(domain);
        });
    } else {
        // Если не в контексте Битрикс24, пробуем получить из POST данных
        domainText.textContent = 'Не в контексте Битрикс24';
        domainSpinner.style.display = 'none';

        // Для разработки можно раскомментировать:
        // currentDomain = 'test-domain.ru';
        // domainText.textContent = currentDomain;
        // domainHidden.value = currentDomain;
        // addSessionBtn.disabled = false;
        // loadSessions(currentDomain);
        
        showAlert('Приложение должно запускаться в контексте Битрикс24', 'error');
    }
}

// Инициализация при загрузке страницы
document.addEventListener('DOMContentLoaded', initBitrix24);