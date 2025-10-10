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
  $time   = esc($order['created_at'] ?? '');
  $name   = esc($order['customer_name'] ?? 'Guest');
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
  // Compute a URL that works under /admin/ as well as at site root
  $scriptBase = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/'); // e.g., /cupscuddles/admin
  $receiptHref = $receipt !== '' ? ($scriptBase . '/../' . ltrim($receipt, '/')) : '';
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
            <a href="<?= esc('../' . ltrim($receipt, '/')) ?>" target="_blank" rel="noopener" style="margin-left:6px;color:#0ea5e9;">Open</a>
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

  <div class="order-actions">
    <?php
      $receiptUrl = $receipt !== '' ? $receiptHref : '';
      $btnStyle = 'margin-bottom:8px;width:100%;border:none;border-radius:6px;padding:8px 10px;';
      $hasReceipt = $receiptUrl !== '';
      $bg = $hasReceipt ? '#0ea5e9' : '#9ca3af';
      $title = $hasReceipt ? 'Open receipt' : 'No receipt attached';
    ?>
    <button type="button" class="btn-see-receipt" data-receipt-url="<?= esc($receiptUrl) ?>" title="<?= esc($title) ?>" style="<?= esc($btnStyle) ?>background:<?= esc($bg) ?>;color:#fff;">See receipt</button>
    <?php if ($status === 'pending'): ?>
      <button type="button" class="btn-accept" data-id="<?= $id ?>">Accept</button>
      <button type="button" class="btn-reject" data-id="<?= $id ?>">Reject</button>
    <?php elseif ($status === 'preparing'): ?>
      <button type="button" class="btn-ready" data-id="<?= $id ?>">Mark as Ready</button>
    <?php elseif ($status === 'ready'): ?>
      <button type="button" class="btn-complete" data-id="<?= $id ?>">Mark as Picked Up</button>
    <?php endif; ?>
  </div>
</div>
<?php endforeach; ?>