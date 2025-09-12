<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>FCM Test</title></head>
<body>
<pre id="log">Starting…</pre>
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-app-compat.js"></script>
<script src="https://www.gstatic.com/firebasejs/10.12.0/firebase-messaging-compat.js"></script>
<script>
(async () => {
  const log = m => { console.log(m); logEl.textContent += '\n'+m; };
  const logEl = document.getElementById('log');
  firebase.initializeApp({
    apiKey:"AIzaSyBrrSPfXUSCvL4ZHx4P8maqBcGjMAzTk8k",
    authDomain:"coffeeshop-8ce2a.firebaseapp.com",
    projectId:"coffeeshop-8ce2a",
    storageBucket:"coffeeshop-8ce2a.appspot.com",
    messagingSenderId:"398338296558",
    appId:"1:398338296558:web:8c44c2b36eccad9fbdc1ff"
  });
  if(!firebase.messaging.isSupported()){ log('Messaging not supported'); return; }
  const messaging = firebase.messaging();
  if(Notification.permission==='default') await Notification.requestPermission();
  if(Notification.permission!=='granted'){ log('Permission not granted'); return; }
  const reg = await navigator.serviceWorker.register('/firebase-messaging-sw.js');
  await navigator.serviceWorker.ready;
  log('SW ready');
  try{
    const token = await messaging.getToken({
      vapidKey:"BBD435Y3Qib-8dPJ_-eEs2ScDyXZ2WhWzFzS9lmuKv_xQ4LSPcDnZZVqS7FHBtinlM_tNNQYsocQMXCptrchO68",
      serviceWorkerRegistration:reg
    });
    log('TOKEN prefix: '+token.substring(0,24));
    const resp = await fetch('saveAdminFcmToken.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({token})});
    log('Save status '+resp.status);
  }catch(e){ log('getToken error '+e); }
})();
</script>
</body>
</html>