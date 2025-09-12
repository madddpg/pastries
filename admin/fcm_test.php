<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>FCM Test</title></head>
<body>
<h3>FCM Token Test</h3>
<pre id="out">Starting...</pre>
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js"></script>
<script>
(async () => {
  const cfg = {
    apiKey: "AIzaSyBrrSPfXUSCvL4ZHx4P8maqBcGjMAzTk8k",
    authDomain: "coffeeshop-8ce2a.firebaseapp.com",
    projectId: "coffeeshop-8ce2a",
    storageBucket: "coffeeshop-8ce2a.appspot.com",
    messagingSenderId: "398338296558",
    appId: "1:398338296558:web:8c44c2b36eccad9fbdc1ff",
    measurementId: "G-5DGJCENLGV"
  };
  const vapidKey = "BBD435Y3Qib-8dPJ_-eEs2ScDyXZ2WhWzFzS9lmuKv_xQ4LSPcDnZZVqS7FHBtinlM_tNNQYsocQMXCptrchO68";
  const outEl = document.getElementById('out');
  const log = m => { console.log(m); outEl.textContent += '\n'+m; };

  firebase.initializeApp(cfg);

  if (!firebase.messaging.isSupported()) { log('Messaging not supported'); return; }

  // Define messaging BEFORE using it
  const messaging = firebase.messaging();

  if (Notification.permission === 'default') {
    log('Requesting permission...');
    await Notification.requestPermission();
  }
  if (Notification.permission !== 'granted') { log('Permission not granted'); return; }

  log('Registering SW (root)...');
  await navigator.serviceWorker.register('/firebase-messaging-sw.js');
  const reg = await navigator.serviceWorker.ready;
  log('SW ready (state='+(reg.active && reg.active.state)+')');

  try {
    log('Calling getToken...');
    const token = await messaging.getToken({ serviceWorkerRegistration: reg, vapidKey });
    if (!token) { log('Token null'); return; }
    log('TOKEN:\n'+token);
    const resp = await fetch('saveAdminFcmToken.php', {
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({token})
    });
    log('Save status: '+resp.status);
  } catch(e) {
    log('getToken error: '+(e.message||e));
  }
})();
</script>
</body>
</html>