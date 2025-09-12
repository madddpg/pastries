importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js');
importScripts('https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js');

firebase.initializeApp({
  apiKey: "AIzaSyBrrSPfXUSCvL4ZHx4P8maqBcGjMAzTk8k",
  authDomain: "coffeeshop-8ce2a.firebaseapp.com",
  projectId: "coffeeshop-8ce2a",
  storageBucket: "coffeeshop-8ce2a.appspot.com",
  messagingSenderId: "398338296558",
  appId: "1:398338296558:web:8c44c2b36eccad9fbdc1ff"
});

const messaging = firebase.messaging();

messaging.onBackgroundMessage(payload => {
  const d = payload.data || {};
  const title = d.title || 'Notification';
  const body  = d.body  || '';
  const icon  = d.icon  || '/img/kape.png';
  const image = d.image || '/img/logo.png';
  self.registration.showNotification(title, {
    body,
    icon,
    image,
    data: d,
    badge: icon
  });
});