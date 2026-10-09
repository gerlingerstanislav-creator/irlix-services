import '@fontsource-variable/onest/wght.css';
import { createApp } from 'vue';
import App from './App.vue';
import { auth } from './auth';
import '@irlix/ui/styles/base.css';
import './style.css';
const start=async()=>{try{if(await auth.init())createApp(App).mount('#app');}catch(e){console.error(e);document.querySelector('#app').innerHTML='<div style="padding:24px">Не удалось подключиться к сервису авторизации.</div>';}};start();
