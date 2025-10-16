<?php
session_start();
require_once __DIR__ . '/admin/database/db_connect.php';
$db = new Database();
$pdo = $db->opencon();

$token = isset($_GET['token']) ? trim($_GET['token']) : '';
$valid = false;
if ($token !== '') {
    $token_hash = hash('sha256', $token);
    try {
        $st = $pdo->prepare('SELECT expires_at, used_at FROM password_resets WHERE token_hash = ? LIMIT 1');
        $st->execute([$token_hash]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && empty($row['used_at']) && strtotime($row['expires_at']) >= time()) {
            $valid = true;
        }
    } catch (Throwable $e) { $valid = false; }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<title>Reset Password - Cups & Cuddles</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh;">
<div class="container">
  <div class="row justify-content-center">
    <div class="col-12 col-md-6 col-lg-5">
      <div class="card shadow">
        <div class="card-body p-4">
          <h4 class="mb-3 text-center">Reset your password</h4>
          <?php if (!$valid): ?>
            <div class="alert alert-danger">This reset link is invalid or has expired.</div>
          <?php else: ?>
            <div id="msg" class="alert d-none" role="alert"></div>
            <form id="resetForm" onsubmit="submitReset(event)">
              <input type="hidden" id="token" value="<?php echo htmlspecialchars($token, ENT_QUOTES, 'UTF-8'); ?>" />
              <div class="mb-3">
                <label for="password" class="form-label">New password</label>
                <input type="password" class="form-control" id="password" required minlength="8" />
              </div>
              <div class="mb-3">
                <label for="confirm" class="form-label">Confirm password</label>
                <input type="password" class="form-control" id="confirm" required minlength="8" />
              </div>
              <button type="submit" class="btn btn-success w-100">Set new password</button>
            </form>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
function submitReset(e){
  e.preventDefault();
  const msg = document.getElementById('msg');
  msg.classList.add('d-none');
  const token = document.getElementById('token').value;
  const password = document.getElementById('password').value.trim();
  const confirm = document.getElementById('confirm').value.trim();
  fetch('reset_password.php',{
    method:'POST',
    headers:{'Content-Type':'application/x-www-form-urlencoded'},
    body: new URLSearchParams({action:'reset', token, password, confirm_password: confirm}).toString()
  }).then(r=>r.json()).then(data=>{
    msg.classList.remove('d-none');
    msg.classList.toggle('alert-success', !!data.success);
    msg.classList.toggle('alert-danger', !data.success);
    msg.textContent = data.message || (data.success ? 'Done' : 'Failed');
    if(data.success){
      setTimeout(()=>{ window.location.href = 'index.php'; }, 2000);
    }
  }).catch(()=>{
    msg.classList.remove('d-none');
    msg.classList.add('alert-danger');
    msg.textContent = 'Something went wrong.';
  });
}
</script>
</body>
</html>