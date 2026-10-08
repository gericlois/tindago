<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

// Orders must total at least this much to be placed — applies to the
// member's own cart checkout only, not admin-added gift orders (which are
// pinned at total_amount=0 and go through a separate claim flow). Set by a
// super admin on basics/admin/minimum_order.php.
$minimum_order = basics_minimum_order($conn);

$member = basics_get_member($conn, basics_current_user_id());
$errors = [];

$stmt = $conn->prepare("SELECT * FROM basics_orders WHERE member_id = ? AND status = 'draft'");
$stmt->bind_param('i', $member['id']);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

$is_ajax = ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $order) {
    $action = $_POST['action'] ?? '';

    if ($action === 'remove_item') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM basics_order_items WHERE id = ? AND order_id = ?");
        $stmt->bind_param('ii', $item_id, $order['id']);
        $stmt->execute();
        $stmt->close();
        $stmt = $conn->prepare("UPDATE basics_orders SET total_amount = (SELECT COALESCE(SUM(line_total),0) FROM basics_order_items WHERE order_id = ?) WHERE id = ?");
        $stmt->bind_param('ii', $order['id'], $order['id']);
        $stmt->execute();
        $stmt->close();

        if ($is_ajax) {
            $order_total = (float) $conn->query("SELECT total_amount FROM basics_orders WHERE id = " . (int) $order['id'])->fetch_assoc()['total_amount'];
            $item_count = (int) $conn->query("SELECT COUNT(*) AS c FROM basics_order_items WHERE order_id = " . (int) $order['id'])->fetch_assoc()['c'];
            $available = basics_credit_available($conn, $member);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => true,
                'order_total' => $order_total,
                'order_total_formatted' => format_price($order_total),
                'available' => $available,
                'exceeds_credit' => $order_total > $available,
                'below_minimum' => $order_total < $minimum_order,
                'item_count' => $item_count,
                'cart_count' => basics_cart_item_count($conn, basics_current_user_id()),
            ]);
            exit;
        }
        redirect('/basics/cart.php');
    } elseif ($action === 'update_quantity') {
        $item_id = (int) ($_POST['item_id'] ?? 0);
        $delta = (int) ($_POST['delta'] ?? 0);

        $stmt = $conn->prepare("SELECT * FROM basics_order_items WHERE id = ? AND order_id = ?");
        $stmt->bind_param('ii', $item_id, $order['id']);
        $stmt->execute();
        $item = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($item) {
            $new_qty = max(1, $item['quantity'] + $delta);
            $line_total = round($item['unit_price'] * $new_qty, 2);
            $stmt = $conn->prepare("UPDATE basics_order_items SET quantity = ?, line_total = ? WHERE id = ?");
            $stmt->bind_param('idi', $new_qty, $line_total, $item_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("UPDATE basics_orders SET total_amount = (SELECT COALESCE(SUM(line_total),0) FROM basics_order_items WHERE order_id = ?) WHERE id = ?");
            $stmt->bind_param('ii', $order['id'], $order['id']);
            $stmt->execute();
            $stmt->close();

            if ($is_ajax) {
                $order_total = (float) $conn->query("SELECT total_amount FROM basics_orders WHERE id = " . (int) $order['id'])->fetch_assoc()['total_amount'];
                $available = basics_credit_available($conn, $member);
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => true,
                    'item_id' => $item_id,
                    'quantity' => $new_qty,
                    'line_total_formatted' => format_price($line_total),
                    'order_total' => $order_total,
                    'order_total_formatted' => format_price($order_total),
                    'available' => $available,
                    'exceeds_credit' => $order_total > $available,
                    'below_minimum' => $order_total < $minimum_order,
                    'cart_count' => basics_cart_item_count($conn, basics_current_user_id()),
                ]);
                exit;
            }
        } elseif ($is_ajax) {
            header('Content-Type: application/json');
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Item not found.']);
            exit;
        }
        redirect('/basics/cart.php');
    } elseif ($action === 'place_order') {
        $stmt = $conn->prepare("SELECT COUNT(*) AS c FROM basics_order_items WHERE order_id = ?");
        $stmt->bind_param('i', $order['id']);
        $stmt->execute();
        $item_count = $stmt->get_result()->fetch_assoc()['c'];
        $stmt->close();

        $delivery_location = ($_POST['delivery_location'] ?? '') === 'company' ? 'company' : 'home';
        $delivery_address = $delivery_location === 'company' ? trim((string) ($member['employer_address'] ?? '')) : trim((string) ($member['address'] ?? ''));

        if ($item_count == 0) {
            $errors[] = 'Add at least one item before placing your order.';
        } elseif ($order['total_amount'] < $minimum_order) {
            $errors[] = 'This order (' . format_price($order['total_amount']) . ') is below the minimum order of ' . format_price($minimum_order) . '.';
        } elseif ($delivery_location === 'company' && $delivery_address === '') {
            $errors[] = 'You don\'t have an employer/office address on file. Add one in My Account, or choose Home delivery.';
        } else {
            $available = basics_credit_available($conn, $member);
            if ($member['membership_status'] !== 'active') {
                $errors[] = 'Your account is not currently active for ordering.';
            } elseif ($order['total_amount'] > $available) {
                $errors[] = 'This order (' . format_price($order['total_amount']) . ') exceeds your available purchase balance (' . format_price($available) . ').';
            } else {
                $stmt = $conn->prepare("UPDATE basics_orders SET status = 'pending', placed_at = NOW(), delivery_location = ?, delivery_address = ? WHERE id = ?");
                $stmt->bind_param('ssi', $delivery_location, $delivery_address, $order['id']);
                $stmt->execute();
                $stmt->close();

                basics_record_order_status($conn, $order['id'], 'pending', $member['full_name']);

                $stmt = $conn->prepare("UPDATE basics_members SET last_activity_at = NOW() WHERE id = ?");
                $stmt->bind_param('i', $member['id']);
                $stmt->execute();
                $stmt->close();

                basics_notify($conn, $member, "Hi {$member['full_name']}, we've received your order of " . format_price($order['total_amount']) . ". We'll notify you once it's confirmed and again once it's delivered. - TindaGo", 'order', 'Order placed', '/order_view.php?id=' . $order['id']);

                redirect('/basics/orders.php?placed=1');
            }
        }
    }
}

$items = null;
if ($order) {
    $stmt = $conn->prepare("SELECT oi.*, p.name, p.sku, p.unit FROM basics_order_items oi
                             JOIN basics_products p ON p.id = oi.product_id
                             WHERE oi.order_id = ? ORDER BY oi.id ASC");
    $stmt->bind_param('i', $order['id']);
    $stmt->execute();
    $items = $stmt->get_result();
}

$available = basics_credit_available($conn, $member);

$page_title = 'Cart';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <a href="<?= BASICS_URL ?>/catalog.php" class="small" style="color:inherit;">&larr; Back to Catalog</a>
    <span class="slbl">Your Order</span>
    <h1 class="stitle">Your <span>Cart</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="container py-5">
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if (!$order || $items->num_rows === 0): ?>
    <div class="panel-card text-center">
      <p class="text-muted mb-3">Your cart is empty.</p>
      <a href="<?= BASICS_URL ?>/catalog.php" class="btn-red"><i class="fas fa-basket-shopping"></i>Browse Catalog</a>
    </div>
  <?php else: ?>
    <p class="small text-muted mb-3">Available purchase balance: <strong><?= format_price($available) ?></strong></p>
    <div class="table-responsive mb-4">
      <table class="table-theme" id="cartTable">
        <thead><tr><th>Product</th><th>Qty</th><th>Unit Price</th><th>Line Total</th><th></th></tr></thead>
        <tbody>
        <?php while ($item = $items->fetch_assoc()): ?>
          <tr data-item-id="<?= (int) $item['id'] ?>">
            <td><?= sanitize($item['name']) ?></td>
            <td>
              <div class="qty-stepper">
                <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">&minus;</button>
                <span class="qty-value"><?= (int) $item['quantity'] ?></span>
                <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">+</button>
                <span class="text-muted small ms-1"><?= sanitize($item['unit']) ?></span>
              </div>
            </td>
            <td><?= format_price($item['unit_price']) ?></td>
            <td class="line-total"><?= format_price($item['line_total']) ?></td>
            <td>
              <button type="button" class="btn-chip btn-chip-outline remove-item-btn"><i class="fas fa-trash"></i></button>
            </td>
          </tr>
        <?php endwhile; ?>
        </tbody>
      </table>
    </div>

    <div class="panel-card">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0">Order Total</h2>
        <div class="stitle mb-0" style="font-size:1.6rem;" id="orderTotal"><?= format_price($order['total_amount']) ?></div>
      </div>
      <form method="post" id="placeOrderForm">
        <input type="hidden" name="action" value="place_order">

        <div class="mb-3">
          <label class="flbl d-block">Deliver To</label>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="delivery_location" id="deliverHome" value="home" checked>
            <label class="form-check-label" for="deliverHome">
              Home Address — <?= $member['address'] ? sanitize($member['address']) : '—' ?>
            </label>
          </div>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="delivery_location" id="deliverCompany" value="company" <?= empty($member['employer_address']) ? 'disabled' : '' ?>>
            <label class="form-check-label" for="deliverCompany">
              Company Address — <?= $member['employer_address'] ? sanitize($member['employer_address']) : 'Not on file' ?>
            </label>
          </div>
          <?php if (empty($member['employer_address'])): ?>
            <p class="text-muted small mb-0 mt-1">Add an employer/office address in <a href="<?= BASICS_URL ?>/account.php">My Account</a> to enable company delivery.</p>
          <?php endif; ?>
        </div>

        <button type="submit" class="btn-red w-100 justify-content-center" id="placeOrderBtn" <?= ($order['total_amount'] > $available || $order['total_amount'] < $minimum_order) ? 'disabled' : '' ?>><i class="fas fa-check"></i>Place Order</button>
      </form>
      <p class="small text-muted mt-2 mb-0" id="exceedsCreditMsg" style="<?= $order['total_amount'] > $available ? '' : 'display:none;' ?>">This order exceeds your available purchase balance.</p>
      <p class="small text-muted mt-2 mb-0" id="belowMinimumMsg" style="<?= $order['total_amount'] < $minimum_order ? '' : 'display:none;' ?>">Minimum order is <?= format_price($minimum_order) ?>.</p>
    </div>
  <?php endif; ?>
</div>

<div class="modal fade" id="removeItemModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Remove Item</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <p class="mb-0">Remove this item from your cart?</p>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn-chip btn-chip-primary" id="confirmRemoveBtn"><i class="fas fa-trash"></i> Remove</button>
      </div>
    </div>
  </div>
</div>

<script>
function basicsUpdateCartBadge(count) {
  var link = document.getElementById('basicsCartLink');
  if (!link) return;
  var badge = document.getElementById('basicsCartBadge');
  if (count > 0) {
    if (!badge) {
      badge = document.createElement('span');
      badge.id = 'basicsCartBadge';
      badge.className = 'nav-cart-badge';
      link.appendChild(badge);
    }
    badge.textContent = count;
  } else if (badge) {
    badge.remove();
  }
}

document.addEventListener('DOMContentLoaded', function () {
  var table = document.getElementById('cartTable');
  if (!table) return;

  var orderTotal = document.getElementById('orderTotal');
  var placeOrderBtn = document.getElementById('placeOrderBtn');
  var exceedsCreditMsg = document.getElementById('exceedsCreditMsg');
  var belowMinimumMsg = document.getElementById('belowMinimumMsg');

  function applyOrderState(data) {
    orderTotal.textContent = data.order_total_formatted;
    placeOrderBtn.disabled = !!data.exceeds_credit || !!data.below_minimum;
    exceedsCreditMsg.style.display = data.exceeds_credit ? '' : 'none';
    belowMinimumMsg.style.display = data.below_minimum ? '' : 'none';
    basicsUpdateCartBadge(data.cart_count);
  }

  function postCart(body) {
    return fetch(window.location.pathname, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: body
    }).then(function (res) { return res.json(); });
  }

  table.addEventListener('click', function (e) {
    var row = e.target.closest('tr[data-item-id]');
    if (!row) return;
    var itemId = row.dataset.itemId;

    if (e.target.closest('.qty-minus') || e.target.closest('.qty-plus')) {
      var delta = e.target.closest('.qty-minus') ? -1 : 1;
      var qtyEl = row.querySelector('.qty-value');
      var currentQty = parseInt(qtyEl.textContent, 10);
      if (delta < 0 && currentQty <= 1) return;

      var body = new URLSearchParams({ action: 'update_quantity', item_id: itemId, delta: delta });
      row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

      postCart(body).then(function (data) {
        if (data.success) {
          qtyEl.textContent = data.quantity;
          row.querySelector('.line-total').textContent = data.line_total_formatted;
          applyOrderState(data);
        }
      }).finally(function () {
        row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
      });
    } else if (e.target.closest('.remove-item-btn')) {
      pendingRemoveRow = row;
      new bootstrap.Modal(document.getElementById('removeItemModal')).show();
    }
  });

  var pendingRemoveRow = null;
  document.getElementById('confirmRemoveBtn').addEventListener('click', function () {
    var modalEl = document.getElementById('removeItemModal');
    bootstrap.Modal.getInstance(modalEl).hide();
    if (!pendingRemoveRow) return;
    var row = pendingRemoveRow;
    pendingRemoveRow = null;
    var itemId = row.dataset.itemId;
    var body = new URLSearchParams({ action: 'remove_item', item_id: itemId });
    row.querySelectorAll('button').forEach(function (b) { b.disabled = true; });

    postCart(body).then(function (data) {
      if (data.success) {
        if (data.item_count === 0) {
          window.location.reload();
          return;
        }
        row.remove();
        applyOrderState(data);
      }
    }).finally(function () {
      row.querySelectorAll('button').forEach(function (b) { b.disabled = false; });
    });
  });
});
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
