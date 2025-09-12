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
  const logEl = document.getElementById('out');
  const out = m => { console.log(m); logEl.textContent += '\\n'+m; };

  firebase.initializeApp(cfg);
  if (!firebase.messaging.isSupported()) { out('Messaging not supported'); return; }

  if (Notification.permission === 'default') {
    out('Requesting permission...');
    await Notification.requestPermission();
  }
  if (Notification.permission !== 'granted') { out('Permission not granted'); return; }

  out('Registering SW at root...');
  await navigator.serviceWorker.register('/firebase-messaging-sw.js'); // CHANGED
  out('Waiting for active worker...');
  const reg = await navigator.serviceWorker.ready;
  out('SW ready (state='+(reg.active && reg.active.state)+')');

  const messaging = firebase.messaging();
  try {
    out('Calling getToken...');
    const token = await messaging.getToken({ serviceWorkerRegistration: reg, vapidKey });
    if (!token) { out('Token null'); return; }
    out('TOKEN:\\n'+token);
    const resp = await fetch('/cupscuddles/admin/saveAdminFcmToken.php',{
      method:'POST',
      headers:{'Content-Type':'application/json'},
      body: JSON.stringify({token})
    });
    out('Save status: '+resp.status);
  } catch(e) {
    out('getToken error: '+(e.message||e));
  }
})();
</script>
</body>
</html>