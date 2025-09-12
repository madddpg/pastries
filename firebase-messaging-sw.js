importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyDG0h8OdQy25MbONwuP-77p_F5rfRrmwZk",
  authDomain: "coffeeshop-8ce2a.firebaseapp.com",
  projectId: "coffeeshop-8ce2a",
  storageBucket: "coffeeshop-8ce2a.appspot.com",
  messagingSenderId: "398338296558",
  appId: "1:398338296558:web:8c44c2b36eccad9fbdc1ff"
});

const messaging = firebase.messaging();


messaging.onBackgroundMessage(payload => {
  const n = payload.notification || {};
  self.registration.showNotification(
    n.title || 'New Message',
    {
      body: n.body || '',
      data: payload.data || {},
      icon: '/icon-192.png'
    }
  );
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  const url = e.notification.data?.click_action || '/';
  e.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then(list => {
      const existing = list.find(c => c.url.includes(url));
      return existing ? existing.focus() : clients.openWindow(url);
    })
  );
});
