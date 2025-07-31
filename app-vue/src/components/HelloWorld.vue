<script setup>
import { ref, onMounted } from 'vue'
import Company from '@/assets/logo.svg'

const token = ref('')
const isLoading = ref(false)
const isCopied = ref(false)
const isAnimating = ref(false)
const copyError = ref(false)
const saveSuccess = ref(false)
const saveError = ref(false)

// Функция для загрузки сохраненного токена
const loadSavedToken = async () => {
  try {
    isLoading.value = true;
    
    // 1. Загружаем токен из Bitrix24
    const result = await new Promise((resolve, reject) => {
      BX24.callMethod(
        'app.option.get',
        {
          option: 'data'
        },
        function(result) {
          result.error() ? reject(result.error()) : resolve(result.data());
        }
      );
    });
    
    // 2. Если токен найден, заполняем поле
    if (result && result.api_token) {
      token.value = result.api_token;
      console.log('Токен загружен из Bitrix24:', result.api_token);
    }
    
  } catch (error) {
    console.error('Ошибка загрузки токена:', error);
  } finally {
    isLoading.value = false;
  }
}

// Вызываем при монтировании компонента
onMounted(() => {
  loadSavedToken();
});

const saveToken = async () => {
  if (!token.value.trim()) return;

  isLoading.value = true;
  saveSuccess.value = false;
  saveError.value = false;

  try {
    // 1. Сохраняем в Bitrix24
    await new Promise((resolve, reject) => {
      BX24.callMethod(
        'app.option.set',
        {
          options: {
            data: {
              api_token: token.value.trim()
            }
          }
        },
        function(result) {
          result.error() ? reject(result.error()) : resolve(result.data());
        }
      );
    });

    // 2. Сохраняем в вашу БД
    const response = await fetch('https://bitrix-connector.lead-space.ru/connector_max/Bitrix/AddTokenMax.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        api_token_max: token.value.trim(),
        domain: BX24.getDomain()
      })
    });

    if (!response.ok) {
      throw new Error(await response.text());
    }

    const data = await response.json();
    console.log('Токен сохранен:', data);
    
    saveSuccess.value = true;
    setTimeout(() => { saveSuccess.value = false }, 3000);
  } catch (error) {
    console.error('Ошибка:', error);
    saveError.value = true;
    setTimeout(() => { saveError.value = false }, 3000);
  } finally {
    isLoading.value = false;
  }
};

// Остальные функции (copyToken, fallbackCopyText и т.д.) остаются без изменений
</script>

<template>
  <div class="wrapper">
    <!-- Логотип и заголовок над token-container -->
    <div class="top-section">
      <div class="vue-logo-container">
        <img 
          alt="Company Logo" 
          class="logo" 
          :src="Company" 
          width="180" 
          height="125" 
        />
      </div>
      
      <div class="header">
        <h1 class="title">Коннектор Битрикс24 + MAX</h1>
      </div>
    </div>

    <!-- Основная форма с полем ввода токена -->
    <div class="token-container">
      <div class="token-card">
        <div class="token-content">
          <div class="token-info">
            <h3>Токен бота из приложения MAX</h3>
            <input
              v-model="token"
              type="text"
              class="token-input"
              placeholder="Введите ваш токен"
            />
            <p class="instruction">Необходимо получить токен бота в приложении MAX через @MasterBot</p>
            <p v-if="copyError" class="error-message">
              Не удалось скопировать. Пожалуйста, скопируйте вручную.
            </p>
            <p v-if="saveSuccess" class="success-message">
              Токен успешно сохранен!
            </p>
            <p v-if="saveError" class="error-message">
              Ошибка при сохранении токена
            </p>
          </div>
        </div>
        
        <div class="token-actions">
          <button 
            @click="saveToken"
            :disabled="isLoading || !token.trim()"
            class="action-btn save-btn"
            :class="{ loading: isLoading }"
          >
            <span v-if="isLoading">Сохранение...</span>
            <span v-else>Сохранить токен</span>
          </button>
          
          <button 
            @click="copyToken"
            :disabled="!token"
            class="action-btn copy-btn"
            :class="{ copied: isCopied, animate: isAnimating, 'error-btn': copyError }"
          >
            {{ isCopied ? 'Скопировано!' : copyError ? 'Ошибка!' : 'Копировать токен' }}
          </button>
        </div>
      </div>
    </div>

    <!-- Обращение в поддержку под token-container -->
    <div class="footer">
      <a href="https://t.me/marketplace_LeadSpace_bot" target="_blank">Нужна помощь? Обратитесь в нашу поддержку</a>
    </div>
  </div>
</template>

<style scoped>
.wrapper {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 1rem;
  padding: 2rem;
  width: 600px;
  margin: 0 auto;
}

.top-section {
  display: flex;
  flex-direction: column;
  align-items: center;
  width: 100%;
}

.vue-logo-container {
  margin-bottom: 1rem;
}

.header {
  text-align: center;
  width: 100%;
}

.title {
  color: #2c3e50;
  font-size: 2rem;
  font-weight: 600;
  margin-bottom: 0.5rem;
}

.token-container {
  width: 100%;
  background: white;
  border-radius: 12px;
  box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
  border: 1px solid #e0e0e0;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

.token-card {
  padding: 1.5rem;
  text-align: left;
}

.token-content {
  margin-bottom: 2rem;
}

.token-info h3 {
  color: #2c3e50;
  font-size: 1.2rem;
  margin-bottom: 0.5rem;
  text-align: left;
}

.token-input {
  width: 100%;
  padding: 0.8rem 1rem;
  border: 1px solid #ddd;
  border-radius: 8px;
  font-size: 1rem;
  margin-bottom: 0.5rem;
}

.token-input:focus {
  outline: none;
  border-color: #0952C9;
  box-shadow: 0 0 0 2px rgba(9, 82, 201, 0.2);
}

.instruction {
  color: #7f8c8d;
  font-size: 0.9rem;
  margin-top: 0.5rem;
  text-align: left;
}

.error-message {
  color: #ff4444;
  font-size: 0.9rem;
  margin-top: 0.5rem;
  text-align: left;
}

.success-message {
  color: #00C851;
  font-size: 0.9rem;
  margin-top: 0.5rem;
  text-align: left;
}

.token-actions {
  display: flex;
  gap: 1rem;
  justify-content: space-around;
}

.action-btn {
  padding: 0.8rem 1.5rem;
  border-radius: 8px;
  font-weight: 500;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
  text-align: center;
  min-width: 230px;
}

.action-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.save-btn {
  background: #00C851;
  color: white;
}

.save-btn:hover:not(:disabled) {
  background: #007E33;
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 200, 81, 0.3);
}

.save-btn.loading {
  background: #66BB6A;
}

.copy-btn {
  background: #0952C9;
  color: white;
}

.copy-btn:hover:not(:disabled) {
  background: rgba(61, 129, 240, 1);
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(61, 129, 240, 1);
}

.copy-btn.copied {
  background: rgba(61, 129, 240, 1);
}

.copy-btn.error-btn {
  background: #ff4444;
}

.copy-btn.animate::after {
  content: '';
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(255, 255, 255, 0.3);
  transform: translateX(-100%);
  animation: shine 1s;
}

.footer {
  text-align: center;
  color: #95a5a6 !important;
  font-size: 0.9rem;
  margin-top: 1rem;
  width: 100%;
}
.footer a:hover {
  color: #0952C9 !important;
}

@keyframes spin {
  to { transform: rotate(360deg); }
}

@keyframes shine {
  100% {
    transform: translateX(100%);
  }
}
</style>