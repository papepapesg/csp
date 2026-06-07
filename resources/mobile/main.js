import { createApp } from 'vue';
import App from './App.vue';
import './mobile.css';

// Register the app's root-scope service worker (installable + offline shell).
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/m' }).catch(() => {});
    });
}

createApp(App).mount('#sophix-mobile');
