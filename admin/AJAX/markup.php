<?php
// expects: $orders (array)
if (empty($orders)) {
    echo '<div class="empty-state">No orders found.</div>';
    return;
}

function esc($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

foreach ($orders as $order):
  $id     = (int)$order['transac_id'];
  $ref    = $order['reference_number'] !== null ? esc($order['reference_number']) : ('#' . $id);
  $status = strtolower((string)$order['status']);
  $time   = esc($order['created_at']);
  $name   = esc($order['customer_name'] ?? 'Guest');
  $total  = number_format((float)($order['total_amount'] ?? 0), 2);
  $pickup = trim((string)($order['pickup_time'] ?? ''));
  $note   = trim((string)($order['special_instructions'] ?? ''));
  $method = strtolower(trim((string)($order['payment_method'] ?? 'cash'))); // 'cash' or 'gcash'
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
      <p>Payment:
        <span class="payment-badge <?= $method ?>"><?= esc(ucfirst($method)) ?></span>
      </p>
    </div>
    <div class="pickup-info" style="margin: 10px 0;">
      <?php if ($pickup !== ''): ?>
        <div>Pickup Time: <?= esc($pickup) ?></div>
      <?php endif; ?>
      <?php if ($note !== ''): ?>
        <div>Note: <?= esc($note) ?></div>
      <?php endif; ?>
    </div>
  </div>

  <div class="order-items">
    <?php if (!empty($order['items'])): ?>
      <?php foreach ($order['items'] as $it): ?>
        <div class="item">
          • <?= esc($it['name']) ?>
          <?php if (!empty($it['size'])): ?>
            (<?= esc($it['size']) ?>)
          <?php endif; ?>
          × <?= (int)$it['quantity'] ?> - ₱<?= number_format((float)$it['price'], 2) ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <div class="order-actions">
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