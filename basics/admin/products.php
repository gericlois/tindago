<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) $_POST['id'];

    $stmt = $conn->prepare("SELECT name, image FROM basics_products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    try {
        $stmt = $conn->prepare("DELETE FROM basics_products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, 'delete_basics_product', 'Deleted Basics product "' . ($product['name'] ?? "#$id") . '"');

        if ($product && $product['image'] && is_file(UPLOAD_PATH . 'basics_products/' . $product['image'])) {
            unlink(UPLOAD_PATH . 'basics_products/' . $product['image']);
        }
    } catch (mysqli_sql_exception $e) {
        // Product has existing order items referencing it (ON DELETE RESTRICT) — ignore, just don't delete it.
    }
    redirect('/basics/admin/products.php');
}

$category_filter = $_GET['category'] ?? '';
$valid_categories = ['Rice', 'Food Essentials', 'Cooking Products', 'Beverages', 'Homecare', 'Personal Care', 'Palengke Items', 'Frozen Meat Products', 'Bread & Snacks'];
$sql = "SELECT * FROM basics_products";
if (in_array($category_filter, $valid_categories, true)) {
    $sql .= " WHERE category = '" . $conn->real_escape_string($category_filter) . "'";
}
$sql .= " ORDER BY category ASC, name ASC";
$products = $conn->query($sql);

$page_title = 'Basics Products';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <h1 class="stitle" style="font-size:2rem;">Products</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-4">
    <div class="d-flex flex-wrap gap-2">
      <a href="<?= BASE_URL ?>/basics/admin/products.php" class="filter-pill <?= $category_filter === '' ? 'active' : '' ?>">All</a>
      <?php foreach ($valid_categories as $cat): ?>
        <a href="<?= BASE_URL ?>/basics/admin/products.php?category=<?= urlencode($cat) ?>" class="filter-pill <?= $category_filter === $cat ? 'active' : '' ?>"><?= sanitize($cat) ?></a>
      <?php endforeach; ?>
    </div>
    <div class="d-flex gap-2">
      <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
      <a href="<?= BASE_URL ?>/basics/admin/product_edit.php" class="btn-red"><i class="fas fa-plus"></i>Add Product</a>
    </div>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Photo</th><th>SKU</th><th>Category</th><th>Name</th><th>Unit</th><th>SRP</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($products->num_rows === 0): ?>
        <tr><td colspan="8" class="text-muted">No products found.</td></tr>
      <?php endif; ?>
      <?php while ($p = $products->fetch_assoc()): ?>
        <tr>
          <td>
            <?php if ($p['image']): ?>
              <img src="<?= UPLOAD_URL ?>basics_products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>" class="product-thumb">
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td><code><?= sanitize($p['sku']) ?></code></td>
          <td><?= sanitize($p['category']) ?></td>
          <td><?= sanitize($p['name']) ?></td>
          <td><?= sanitize($p['unit']) ?></td>
          <td><?= $p['srp'] > 0 ? format_price($p['srp']) : '<span class="text-muted">TBD</span>' ?></td>
          <td>
            <span class="pill pill-<?= $p['status'] === 'active' ? 'completed' : 'cancelled' ?>"><?= sanitize($p['status']) ?></span>
            <?php if ($p['is_featured']): ?><span class="pill pill-approved"><i class="fas fa-star"></i> Featured</span><?php endif; ?>
            <?php if ($p['flash_deal_price'] !== null && $p['flash_deal_ends_at'] && strtotime($p['flash_deal_ends_at']) > time()): ?>
              <span class="pill pill-pending"><i class="fas fa-bolt"></i> Deal till <?= date('M j, g:ia', strtotime($p['flash_deal_ends_at'])) ?></span>
            <?php endif; ?>
          </td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/basics/admin/product_edit.php?id=<?= (int) $p['id'] ?>" class="btn-chip btn-chip-outline">Edit</a>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Delete this product? Only possible if it has no order history.');">Delete</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
