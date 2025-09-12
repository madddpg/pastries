importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

firebase.initializeApp({
  apiKey: "AIzaSyBrrSPfXUSCvL4ZHx4P8maqBcGjMAzTk8k",
  authDomain: "coffeeshop-8ce2a.firebaseapp.com",
  projectId: "coffeeshop-8ce2a",
  storageBucket: "coffeeshop-8ce2a.appspot.com",
  messagingSenderId: "398338296558",
  appId: "1:398338296558:web:8c44c2b36eccad9fbdc1ff",
  measurementId: "G-5DGJCENLGV"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(payload => {
  const n = payload.notification || {};
  self.registration.showNotification(n.title || 'Notification', {
    body: n.body || '',
    data: payload.data || {},
    icon: '/icon-192.png'
  });
});