<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/includes/module.php';
require __DIR__ . '/includes/functions.php';

require_basics_access($conn);

$member = basics_get_member($conn, basics_current_user_id());

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_to_cart') {
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $quantity = max(1, (int) ($_POST['quantity'] ?? 1));

    $stmt = $conn->prepare("SELECT * FROM basics_products WHERE id = ? AND status = 'active'");
    $stmt->bind_param('i', $product_id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($product) {
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT id FROM basics_orders WHERE member_id = ? AND status = 'draft'");
            $stmt->bind_param('i', $member['id']);
            $stmt->execute();
            $order = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$order) {
                $stmt = $conn->prepare("INSERT INTO basics_orders (member_id, status) VALUES (?, 'draft')");
                $stmt->bind_param('i', $member['id']);
                $stmt->execute();
                $order_id = $stmt->insert_id;
                $stmt->close();
            } else {
                $order_id = $order['id'];
            }

            $stmt = $conn->prepare("SELECT id, quantity FROM basics_order_items WHERE order_id = ? AND product_id = ?");
            $stmt->bind_param('ii', $order_id, $product_id);
            $stmt->execute();
            $existing_item = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($existing_item) {
                $new_qty = $existing_item['quantity'] + $quantity;
                $line_total = round($product['srp'] * $new_qty, 2);
                $stmt = $conn->prepare("UPDATE basics_order_items SET quantity = ?, line_total = ? WHERE id = ?");
                $stmt->bind_param('idi', $new_qty, $line_total, $existing_item['id']);
                $stmt->execute();
                $stmt->close();
            } else {
                $line_total = round($product['srp'] * $quantity, 2);
                $stmt = $conn->prepare("INSERT INTO basics_order_items (order_id, product_id, quantity, unit_price, line_total) VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param('iiidd', $order_id, $product_id, $quantity, $product['srp'], $line_total);
                $stmt->execute();
                $stmt->close();
            }

            $stmt = $conn->prepare("UPDATE basics_orders SET total_amount = (SELECT COALESCE(SUM(line_total),0) FROM basics_order_items WHERE order_id = ?) WHERE id = ?");
            $stmt->bind_param('ii', $order_id, $order_id);
            $stmt->execute();
            $stmt->close();

            $conn->commit();

            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                echo json_encode(['success' => true, 'cart_count' => basics_cart_item_count($conn, basics_current_user_id())]);
                exit;
            }
            redirect('/basics/catalog.php?added=1');
        } catch (Exception $e) {
            $conn->rollback();
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
                header('Content-Type: application/json');
                http_response_code(500);
                echo json_encode(['success' => false]);
                exit;
            }
        }
    } elseif (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
        header('Content-Type: application/json');
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Product not found.']);
        exit;
    }
}

$category_filter = $_GET['category'] ?? '';
$valid_categories = ['Rice', 'Food Essentials', 'Cooking Products', 'Beverages', 'Homecare', 'Personal Care', 'Palengke Items', 'Frozen Meat Products', 'Bread & Snacks'];
$sql = "SELECT * FROM basics_products WHERE status = 'active'";
if (in_array($category_filter, $valid_categories, true)) {
    $sql .= " AND category = '" . $conn->real_escape_string($category_filter) . "'";
}
$sql .= " ORDER BY name ASC";
$products = $conn->query($sql);

$cart_count = basics_cart_item_count($conn, basics_current_user_id());

$page_title = 'Catalog';
require __DIR__ . '/../includes/header.php';
require __DIR__ . '/../includes/navbar.php';
?>

<div class="inner-hero">
  <div class="container">
    <span class="slbl">Grocery Catalog</span>
    <h1 class="stitle">Browse <span>Basic Needs</span></h1>
    <div class="sline"></div>
  </div>
</div>

<div class="shop-bg py-5">
  <div class="container">
    <div id="cartToast" class="sucmsg mb-4<?= isset($_GET['added']) ? ' is-visible' : '' ?>" style="<?= isset($_GET['added']) ? '' : 'display:none;' ?>">
      <p class="mb-0">Added to cart! <a href="<?= BASICS_URL ?>/cart.php">View Cart</a></p>
    </div>

    <div class="d-flex flex-wrap align-items-center gap-3 mb-4">
      <button type="button" class="btn-outline-theme" data-bs-toggle="collapse" data-bs-target="#scheduleInfo"><i class="fas fa-circle-info"></i>Ordering &amp; Payment Policy</button>
    </div>

    <div class="collapse mb-4" id="scheduleInfo">
        <div class="panel-card">
          <p class="mb-0">Order any time &mdash; there's no fixed ordering window. Delivery does not wait on payment: orders are delivered on schedule, and your balance is due <strong>7 days after your order is actually delivered</strong>.</p>
        </div>
    </div>

    <div class="panel-card">
      <div class="mb-3">
        <div class="position-relative">
          <input type="text" id="catalogSearch" class="fctrl" placeholder="Search by product name or SKU..." style="padding-left:40px;" autocomplete="off">
          <i class="fas fa-magnifying-glass" style="position:absolute;left:14px;top:50%;transform:translateY(-50%);color:#999;"></i>
        </div>
      </div>

      <div class="filter-pill-scroll mb-4">
        <a href="<?= BASICS_URL ?>/catalog.php" class="filter-pill <?= $category_filter === '' ? 'active' : '' ?>">All</a>
        <?php foreach ($valid_categories as $cat): ?>
          <a href="<?= BASICS_URL ?>/catalog.php?category=<?= urlencode($cat) ?>" class="filter-pill <?= $category_filter === $cat ? 'active' : '' ?>"><?= sanitize($cat) ?></a>
        <?php endforeach; ?>
      </div>

      <p id="catalogNoResults" class="text-muted" style="display:none;">No products match your search.</p>

      <div class="basics-catalog-grid" id="catalogGrid">
        <?php if ($products->num_rows === 0): ?>
          <p class="text-muted">No products found.</p>
        <?php endif; ?>
        <?php while ($product = $products->fetch_assoc()): ?>
          <div class="basics-product-card catalog-item" data-name="<?= sanitize(strtolower($product['name'])) ?>" data-sku="<?= sanitize(strtolower($product['sku'])) ?>">
            <?php if ($product['image']): ?>
              <img src="<?= UPLOAD_URL ?>basics_products/<?= sanitize($product['image']) ?>" alt="<?= sanitize($product['name']) ?>" class="basics-product-tile">
            <?php else: ?>
              <div class="basics-product-tile-empty"><i class="fas fa-basket-shopping"></i></div>
            <?php endif; ?>
            <div class="basics-product-body">
              <div class="basics-product-name"><?= sanitize($product['name']) ?></div>
              <div class="basics-product-unit"><?= sanitize($product['unit']) ?></div>
              <div class="basics-product-price">
                <?= $product['srp'] > 0 ? format_price($product['srp']) : 'TBD' ?>
              </div>
              <?php if ($product['srp'] > 0): ?>
                <form method="post" class="basics-product-cart-row">
                  <input type="hidden" name="action" value="add_to_cart">
                  <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
                  <div class="qty-stepper">
                    <button type="button" class="qty-btn qty-minus" aria-label="Decrease quantity">&minus;</button>
                    <input type="number" name="quantity" value="1" min="1" class="qty-value-input" readonly>
                    <button type="button" class="qty-btn qty-plus" aria-label="Increase quantity">+</button>
                  </div>
                  <button type="submit" class="btn-red"><i class="fas fa-cart-plus"></i></button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        <?php endwhile; ?>
      </div>
    </div>
  </div>
</div>

<a href="<?= BASICS_URL ?>/cart.php" class="cartfl" id="basicsFloatingCart" style="<?= $cart_count > 0 ? '' : 'display:none;' ?>">
  <i class="fas fa-cart-shopping"></i>
  <span>Cart</span>
  <span class="ccount" id="basicsFloatingCartCount"><?= $cart_count ?></span>
</a>

<script>
  var catalogSearch = document.getElementById('catalogSearch');
  var catalogItems = document.querySelectorAll('.catalog-item');
  var catalogNoResults = document.getElementById('catalogNoResults');

  catalogSearch.addEventListener('input', function () {
    var query = catalogSearch.value.trim().toLowerCase();
    var visibleCount = 0;

    catalogItems.forEach(function (item) {
      var matches = item.dataset.name.indexOf(query) !== -1 || item.dataset.sku.indexOf(query) !== -1;
      item.style.display = matches ? '' : 'none';
      if (matches) visibleCount++;
    });

    catalogNoResults.style.display = (query && visibleCount === 0) ? '' : 'none';
  });

  // Add to cart via fetch — no full page reload/refresh.
  var cartToast = document.getElementById('cartToast');
  var toastTimer = null;

  function showCartToast() {
    cartToast.style.display = '';
    cartToast.classList.add('is-visible');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () {
      cartToast.classList.remove('is-visible');
      cartToast.style.display = 'none';
    }, 3000);
  }

  function updateCartBadge(count) {
    var link = document.getElementById('basicsCartLink');
    if (link) {
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

    var floatingCart = document.getElementById('basicsFloatingCart');
    if (floatingCart) {
      floatingCart.style.display = count > 0 ? '' : 'none';
      var floatingCount = document.getElementById('basicsFloatingCartCount');
      if (floatingCount) floatingCount.textContent = count;
    }
  }

  document.querySelectorAll('.basics-product-cart-row').forEach(function (form) {
    var qtyInput = form.querySelector('.qty-value-input');
    var minusBtn = form.querySelector('.qty-minus');
    var plusBtn = form.querySelector('.qty-plus');

    minusBtn.addEventListener('click', function () {
      var val = Math.max(1, parseInt(qtyInput.value, 10) - 1);
      qtyInput.value = val;
    });
    plusBtn.addEventListener('click', function () {
      var val = parseInt(qtyInput.value, 10) + 1;
      qtyInput.value = val;
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var btn = form.querySelector('button[type="submit"]');
      var originalHtml = btn.innerHTML;
      btn.disabled = true;
      btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

      fetch(window.location.pathname + window.location.search, {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(form)
      })
        .then(function (res) { return res.json(); })
        .then(function (data) {
          if (data.success) {
            btn.innerHTML = '<i class="fas fa-check"></i>';
            showCartToast();
            updateCartBadge(data.cart_count);
            qtyInput.value = 1;
          } else {
            btn.innerHTML = originalHtml;
            alert(data.error || 'Could not add to cart. Please try again.');
          }
        })
        .catch(function () {
          btn.innerHTML = originalHtml;
          alert('Could not add to cart. Please try again.');
        })
        .finally(function () {
          setTimeout(function () {
            btn.innerHTML = originalHtml;
            btn.disabled = false;
          }, 1200);
        });
    });
  });
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
