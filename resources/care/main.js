import { createApp } from 'vue';
import App from './App.vue';
import '../mobile/mobile.css';

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/care' }).catch(() => {});
    });
}

createApp(App).mount('#sophix-care');
