<?php
// expects: $orders (array)
if (empty($orders)) {
    echo '<div class="empty-state">No orders found.</div>';
    return;
}
function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

foreach ($orders as $order):
  $id     = (int)($order['transac_id'] ?? 0);
  $refVal = isset($order['reference_number']) && $order['reference_number'] !== '' ? $order['reference_number'] : $id;
  $ref    = esc($refVal);
  $status = strtolower((string)($order['status'] ?? ''));
  $adminId = isset($order['admin_id']) ? (int)$order['admin_id'] : 0;
  $time   = esc($order['created_at'] ?? '');
  $first  = trim((string)($order['user_FN'] ?? ''));
  $last   = trim((string)($order['user_LN'] ?? ''));
  $fullName = trim($first . ' ' . $last);
  $name   = esc($fullName !== '' ? $fullName : ($order['customer_name'] ?? 'Guest'));
  $total  = number_format((float)($order['total_amount'] ?? 0), 2);
  $pickupRaw = trim((string)($order['pickup_time'] ?? ''));
  $pickupLoc = trim((string)($order['pickup_location'] ?? ''));
  // Format pickup time to 12-hour with AM/PM if it's a valid date/time string
  $pickup = '';
  if ($pickupRaw !== '') {
    $ts = strtotime($pickupRaw);
    if ($ts !== false) {
      $pickup = date('g:i A', $ts);
    } else {
      $pickup = $pickupRaw; // fallback
    }
  }
  $note   = trim((string)($order['special_instructions'] ?? ''));
  $method = strtolower(trim((string)($order['payment_method'] ?? 'gcash')));
  // Be tolerant of either column name in case upstream aliasing changes
  $receiptRaw = isset($order['gcash_receipt_path']) && $order['gcash_receipt_path'] !== ''
      ? (string)$order['gcash_receipt_path']
      : (isset($order['gcash_reciept_path']) ? (string)$order['gcash_reciept_path'] : '');
  $receipt = trim($receiptRaw);
  // Fallback: if DB path missing but gcash, attempt to infer by reference number in img/uploads/gcash
  if ($receipt === '' && $method === 'gcash') {
    $tryExts = ['jpg','jpeg','png','webp'];
    $baseFs = realpath(__DIR__ . '/../../img/uploads/gcash');
    if ($baseFs) {
      // First try by human reference (e.g., CNC-YYYYMMDD-0001)
      if ($ref !== '') {
        foreach ($tryExts as $ex) {
          $candidate = $baseFs . DIRECTORY_SEPARATOR . $ref . '.' . $ex;
          if (@file_exists($candidate)) { $receipt = 'img/uploads/gcash/' . $ref . '.' . $ex; break; }
        }
      }
      // If not found, try by numeric id
      if ($receipt === '' && $id) {
        foreach ($tryExts as $ex) {
          $candidate = $baseFs . DIRECTORY_SEPARATOR . $id . '.' . $ex;
          if (@file_exists($candidate)) { $receipt = 'img/uploads/gcash/' . $id . '.' . $ex; break; }
        }
      }
    }
  }
  // Compute an absolute URL from the site root so it works under /admin or /admin/AJAX
  $script = $_SERVER['SCRIPT_NAME'] ?? '';
  // Remove trailing /admin/... from the current script path to get the site base (e.g., /cupscuddles)
  $siteBase = rtrim(preg_replace('#/admin(?:/AJAX)?/.*$#', '', $script), '/');
  $receiptPath = ltrim($receipt, '/');
  $receiptHref = $receipt !== '' ? ($siteBase . '/' . $receiptPath) : '';
?>
<div class="order-card <?= esc($status) ?>" data-transac-id="<?= $id ?>" data-id="<?= $id ?>">
  <div class="order-header">
    <span class="order-id">Reference Number: <?= $ref ?></span>
    <span class="order-time"><?= $time ?></span>
  </div>

  <div class="customer-info">
    <div>
      <h4><?= $name ?></h4>
      <p>₱<?= $total ?></p>
      <p>
        Payment: <span class="payment-badge <?= esc($method) ?>"><?= esc(ucfirst($method)) ?></span>
        <?php if ($method === 'gcash'): ?>
          <?php if ($receipt !== ''): ?>
            <span style="margin-left:8px;color:#059669;font-weight:600;">Receipt: Attached</span>
            <a href="<?= esc($receiptHref) ?>" target="_blank" rel="noopener" style="margin-left:6px;color:#0ea5e9;">Open</a>
          <?php else: ?>
            <span style="margin-left:8px;color:#9ca3af;">Receipt: None</span>
          <?php endif; ?>
        <?php endif; ?>
      </p>
      <?php if ($method === 'gcash' && $receipt !== ''): ?>
        <div style="margin-top:8px;display:flex;align-items:flex-start;gap:10px;">
          <a href="<?= esc($receiptHref) ?>" target="_blank" rel="noopener" title="Open receipt in new tab">
            <img src="<?= esc($receiptHref) ?>" alt="GCash Receipt" style="max-width:120px;max-height:120px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;object-fit:contain;display:block;" />
          </a>
          <div style="display:flex;flex-direction:column;gap:4px;">
            <a href="<?= esc($receiptHref) ?>" target="_blank" rel="noopener" style="color:#0ea5e9;font-weight:600;">View GCash receipt</a>
            <small style="color:#6b7280;">Click the thumbnail to enlarge</small>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <div class="pickup-info" style="margin: 10px 0;">
      <?php if ($pickupLoc !== ''): ?><div>Pickup Location: <?= esc($pickupLoc) ?></div><?php endif; ?>
      <?php if ($pickup !== ''): ?><div>Pickup Time: <?= esc($pickup) ?></div><?php endif; ?>
      <?php if ($note !== ''): ?><div>Note: <?= esc($note) ?></div><?php endif; ?>
    </div>
  </div>

  <div class="order-items">
    <?php if (!empty($order['items']) && is_array($order['items'])): ?>
      <?php foreach ($order['items'] as $it): ?>
        <div class="item">• <?= esc($it['name'] ?? '') ?>
          <?php if (!empty($it['size'])): ?>(<?= esc($it['size']) ?>)<?php endif; ?>
          <?php if (!empty($it['sugar_level'])): ?> — <span style="color:#64748b;">Sugar: <?= esc($it['sugar_level']) ?></span><?php endif; ?>
          × <?= (int)($it['quantity'] ?? 0) ?> - ₱<?= number_format((float)($it['price'] ?? 0), 2) ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="order-actions" style="display:flex;flex-direction:column;gap:8px;">
    <?php
      $receiptUrl = $receipt !== '' ? $receiptHref : '';
      $hasReceipt = $receiptUrl !== '';
      $bg = $hasReceipt ? '#0ea5e9' : '#9ca3af';
      $title = $hasReceipt ? 'Open receipt' : 'No receipt attached';
    ?>
    <!-- See receipt on top -->
    <button type="button" class="btn-see-receipt" data-receipt-url="<?= esc($receiptUrl) ?>" title="<?= esc($title) ?>" style="width:100%;border:none;border-radius:6px;padding:10px;background:<?= esc($bg) ?>;color:#fff;">See receipt</button>

    <!-- Status and action choices below -->
    <?php if ($status === 'cancelled' && $adminId === 0): ?>
      <div style="padding:8px 10px;border:1px dashed #ef4444;border-radius:8px;color:#ef4444;font-weight:600;text-align:center;background:#fff7f7;">Cancelled by user</div>
    <?php elseif ($status === 'pending'): ?>
      <div style="display:flex;gap:8px;">
        <button type="button" class="btn-accept" data-id="<?= $id ?>" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;font-weight:600;">
          <span class="btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#4caf50"/><path d="M6 10.5l3 3 5-5" stroke="#fff" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
          </span>
          Accept
        </button>
        <button type="button" class="btn-reject" data-id="<?= $id ?>" style="flex:1;display:flex;align-items:center;justify-content:center;gap:6px;font-weight:600;">
          <span class="btn-icon" aria-hidden="true" style="display:inline-flex;align-items:center;">
            <svg width="18" height="18" viewBox="0 0 20 20" fill="none" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="10" fill="#f44336"/><path d="M7 7l6 6M13 7l-6 6" stroke="#fff" stroke-width="2" stroke-linecap="round"/></svg>
          </span>
          Cancel
        </button>
      </div>
    <?php elseif ($status === 'preparing'): ?>
      <button type="button" class="btn-ready" data-id="<?= $id ?>" style="width:100%;">Mark as Ready</button>
    <?php elseif ($status === 'ready'): ?>
      <button type="button" class="btn-complete" data-id="<?= $id ?>" style="width:100%;">Mark as Picked Up</button>
    <?php elseif ($status === 'cancelled'): ?>
      <div style="padding:8px 10px;border:1px solid #e5e7eb;border-radius:8px;color:#6b7280;text-align:center;background:#fff;">Cancelled</div>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>