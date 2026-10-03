<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int) $_POST['id'];

    $stmt = $conn->prepare("SELECT name, image FROM products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $stmt = $conn->prepare("SELECT image FROM product_images WHERE product_id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $highlight_images = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    try {
        // product_images rows cascade automatically via the FK; the files don't.
        $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
        $stmt->bind_param('i', $id);
        $stmt->execute();
        $stmt->close();

        log_activity($conn, 'delete_product', 'Deleted Wellness product "' . ($product['name'] ?? "#$id") . '"');

        if ($product && $product['image'] && is_file(UPLOAD_PATH . 'products/' . $product['image'])) {
            unlink(UPLOAD_PATH . 'products/' . $product['image']);
        }
        foreach ($highlight_images as $h) {
            if (is_file(UPLOAD_PATH . 'products/' . $h['image'])) {
                unlink(UPLOAD_PATH . 'products/' . $h['image']);
            }
        }
    } catch (mysqli_sql_exception $e) {
        // Product has existing orders referencing it (ON DELETE RESTRICT) — ignore, just don't delete it.
    }
    redirect('/admin/products.php');
}

$products = $conn->query("SELECT * FROM products ORDER BY name ASC");

$page_title = 'Products';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Manage</span>
    <h1 class="stitle" style="font-size:2rem;">Products</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <div class="d-flex justify-content-between align-items-center mb-4">
    <button type="button" class="btn-outline-theme no-print" onclick="window.print()"><i class="fas fa-print"></i>Print</button>
    <a href="<?= BASE_URL ?>/admin/product_edit.php" class="btn-red"><i class="fas fa-plus"></i>Add Product</a>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Photo</th><th>Name</th><th>SRP</th><th>Status</th><th>Created</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if ($products->num_rows === 0): ?>
        <tr><td colspan="6" class="text-muted">No products yet.</td></tr>
      <?php endif; ?>
      <?php while ($p = $products->fetch_assoc()): ?>
        <tr>
          <td>
            <?php if ($p['image']): ?>
              <img src="<?= UPLOAD_URL ?>products/<?= sanitize($p['image']) ?>" alt="<?= sanitize($p['name']) ?>" class="product-thumb">
            <?php else: ?>
              <span class="text-muted small">—</span>
            <?php endif; ?>
          </td>
          <td><?= sanitize($p['name']) ?></td>
          <td><?= format_price($p['srp']) ?></td>
          <td><span class="pill pill-<?= $p['status'] === 'active' ? 'completed' : 'inactive' ?>"><?= sanitize($p['status']) ?></span></td>
          <td><?= date('M j, Y', strtotime($p['created_at'])) ?></td>
          <td class="no-print">
            <a href="<?= BASE_URL ?>/admin/product_edit.php?id=<?= (int) $p['id'] ?>" class="btn-chip btn-chip-outline">Edit</a>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
              <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Delete this product? This is only possible if it has no orders.');">Delete</button>
            </form>
          </td>
        </tr>
      <?php endwhile; ?>
      </tbody>
    </table>
  </div>
</div>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
