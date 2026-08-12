/**
 * We'll load the axios HTTP library which allows us to easily issue requests
 * to our Laravel back-end. This library automatically handles sending the
 * CSRF token as a header based on the value of the "XSRF" token cookie.
 */

import axios from 'axios';
window.axios = axios;

window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';

/**
 * Echo/Pusher инициализация перенесена в resources/js/room.js — там же, где
 * настроена авторизация приватных каналов (authEndpoint + X-CSRF-TOKEN).
 * Раньше здесь создавался ВТОРОЙ, неавторизованный экземпляр Echo, который
 * room.js молча перезатирал через window.Echo = ... — но открытое им
 * WebSocket-соединение к Pusher оставалось висеть до закрытия вкладки
 * (на КАЖДОЙ странице сайта, не только в комнате).
 */