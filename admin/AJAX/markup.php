
<?php
// expects: $orders (array)
if (empty($orders)) {
    echo '<div class="empty-state">No orders found.</div>';
    return;
}

foreach ($orders as $order):
?>
<div class="order-card" data-transac-id="<?= (int)$order['transac_id'] ?>" data-id="<?= (int)$order['transac_id'] ?>">
    <!-- paste your existing Live Orders card/row design here -->
    <div class="order-header">
        <div class="order-id">#<?= (int)$order['transac_id'] ?></div>
        <div class="order-status"><?= htmlspecialchars(ucwords($order['status']), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <?php if (!empty($order['items'])): ?>
    <div class="order-items">
        <?php foreach ($order['items'] as $it): ?>
            <div class="order-item">
                <span class="qty"><?= (int)$it['quantity'] ?></span>
                <span class="name"><?= htmlspecialchars($it['name'], ENT_QUOTES, 'UTF-8') ?></span>
                <span class="size"><?= htmlspecialchars($it['size'] ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                <span class="price">₱<?= number_format((float)$it['price'], 2) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="order-actions">
        <?php if ($order['status'] === 'pending'): ?>
            <button type="button" class="btn-accept" data-id="<?= (int)$order['transac_id'] ?>">Accept</button>
            <button type="button" class="btn-reject" data-id="<?= (int)$order['transac_id'] ?>">Reject</button>
        <?php elseif ($order['status'] === 'preparing'): ?>
            <button type="button" class="btn-ready" data-id="<?= (int)$order['transac_id'] ?>">Mark as Ready</button>
        <?php elseif ($order['status'] === 'ready'): ?>
            <button type="button" class="btn-complete" data-id="<?= (int)$order['transac_id'] ?>">Mark as Picked Up</button>
        <?php endif; ?>
    </div>
</div>
<?php endforeach; ?>