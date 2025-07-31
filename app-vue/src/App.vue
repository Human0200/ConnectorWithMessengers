<script setup>
import HelloWorld from './components/HelloWorld.vue'
</script>

<template>
  <div class="app-container">
    <HelloWorld msg="Подключено" token="123" />
  </div>
</template>

<style>
/* Базовые стили для всего приложения */
body {
  margin: 0 !important;
  padding: 0 !important;
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
}

.app-container {
  max-width: 1980px;
  text-align: center;
}
</style>