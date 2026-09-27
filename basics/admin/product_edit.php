<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$id = (int) ($_GET['id'] ?? 0);
$product = [
    'id' => 0, 'sku' => '', 'category' => 'Rice', 'name' => '', 'unit' => '', 'srp' => '0', 'image' => null, 'status' => 'active',
    'is_featured' => 0, 'flash_deal_price' => null, 'flash_deal_ends_at' => null,
];
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM basics_products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc() ?: $product;
    $stmt->close();
}

$errors = [];
$valid_categories = ['Rice', 'Food Essentials', 'Cooking Products', 'Beverages', 'Homecare', 'Personal Care', 'Palengke Items', 'Frozen Meat Products', 'Bread & Snacks'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $sku = trim($_POST['sku'] ?? '');
    $category = in_array($_POST['category'] ?? '', $valid_categories, true) ? $_POST['category'] : 'Rice';
    $name = trim($_POST['name'] ?? '');
    $unit = trim($_POST['unit'] ?? '');
    $srp = (float) ($_POST['srp'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $image = $product['image'];
    $is_featured = !empty($_POST['is_featured']) ? 1 : 0;
    $flash_deal_price_raw = trim($_POST['flash_deal_price'] ?? '');
    $flash_deal_ends_at_raw = trim($_POST['flash_deal_ends_at'] ?? '');
    $flash_deal_price = $flash_deal_price_raw !== '' ? round((float) $flash_deal_price_raw, 2) : null;
    // <input type="datetime-local"> posts "2026-10-01T14:30" (or "...:30:00"
    // if the browser includes seconds) — MySQL DATETIME wants a space
    // instead of the "T", and always wants seconds.
    if ($flash_deal_ends_at_raw !== '') {
        $flash_deal_ends_at = str_replace('T', ' ', $flash_deal_ends_at_raw);
        if (substr_count($flash_deal_ends_at, ':') < 2) {
            $flash_deal_ends_at .= ':00';
        }
    } else {
        $flash_deal_ends_at = null;
    }

    if ($sku === '') $errors[] = 'SKU is required.';
    if ($name === '') $errors[] = 'Product name is required.';
    if ($unit === '') $errors[] = 'Unit is required.';
    if ($srp < 0) $errors[] = 'SRP cannot be negative.';
    // Both-or-neither — a flash deal needs both a price and an end time to
    // mean anything.
    if (($flash_deal_price !== null) !== ($flash_deal_ends_at !== null)) {
        $errors[] = 'Set both a Flash Deal Price and an End Date/Time, or leave both blank.';
    } elseif ($flash_deal_price !== null) {
        if ($flash_deal_price <= 0) {
            $errors[] = 'Flash Deal Price must be greater than 0.';
        } elseif ($srp > 0 && $flash_deal_price >= $srp) {
            $errors[] = 'Flash Deal Price must be less than the regular SRP.';
        }
    }

    if (empty($errors)) {
        $stmt = $conn->prepare("SELECT id FROM basics_products WHERE sku = ? AND id != ?");
        $stmt->bind_param('si', $sku, $id);
        $stmt->execute();
        if ($stmt->get_result()->fetch_assoc()) $errors[] = 'That SKU is already in use.';
        $stmt->close();
    }

    // Remove is only honored when no replacement file was chosen — uploading
    // a new image always takes priority over a stale "remove" checkbox state.
    if (!empty($_POST['remove_image']) && empty($_FILES['image']['name']) && $image) {
        if (is_file(UPLOAD_PATH . 'basics_products/' . $image)) {
            unlink(UPLOAD_PATH . 'basics_products/' . $image);
        }
        $image = null;
    }

    [$image, $image_error] = handle_product_image_upload('image', $image, 'basics_products');
    if ($image_error) $errors[] = $image_error;

    if (empty($errors)) {
        if ($id) {
            $stmt = $conn->prepare("UPDATE basics_products SET sku=?, category=?, name=?, unit=?, srp=?, image=?, status=?, is_featured=?, flash_deal_price=?, flash_deal_ends_at=? WHERE id=?");
            $stmt->bind_param('ssssdssidsi', $sku, $category, $name, $unit, $srp, $image, $status, $is_featured, $flash_deal_price, $flash_deal_ends_at, $id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'update_basics_product', 'Updated Basics product "' . $name . '" (' . $sku . ')');
            redirect('/basics/admin/product_edit.php?id=' . $id . '&saved=1');
        } else {
            $stmt = $conn->prepare("INSERT INTO basics_products (sku, category, name, unit, srp, image, status, is_featured, flash_deal_price, flash_deal_ends_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssdssids', $sku, $category, $name, $unit, $srp, $image, $status, $is_featured, $flash_deal_price, $flash_deal_ends_at);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            log_activity($conn, 'create_basics_product', 'Created Basics product "' . $name . '" (' . $sku . ')');
            redirect('/basics/admin/product_edit.php?id=' . $new_id . '&saved=1');
        }
    }
    $product = array_merge($product, compact('sku', 'category', 'name', 'unit', 'srp', 'status', 'image', 'is_featured', 'flash_deal_price', 'flash_deal_ends_at'));
}

$page_title = $id ? 'Edit Product' : 'Add Product';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/basics/admin/products.php" class="small">&larr; Back to Products</a>
    <h1 class="stitle" style="font-size:2rem;"><?= $id ? 'Edit Product' : 'Add Product' ?></h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['saved'])): ?>
    <div class="sucmsg is-visible"><p>Product saved.</p></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="row g-4">
    <div class="col-12">
      <div class="panel-card">
        <form method="post" enctype="multipart/form-data">
          <div class="row">
            <div class="col-sm-4 mb-3">
              <label class="flbl">SKU</label>
              <input type="text" name="sku" class="fctrl" value="<?= sanitize($product['sku']) ?>" required>
            </div>
            <div class="col-sm-8 mb-3">
              <label class="flbl">Category</label>
              <select name="category" class="fctrl">
                <?php foreach ($valid_categories as $cat): ?>
                  <option value="<?= $cat ?>" <?= $product['category'] === $cat ? 'selected' : '' ?>><?= sanitize($cat) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">Product Name</label>
            <input type="text" name="name" class="fctrl" value="<?= sanitize($product['name']) ?>" required>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Unit (e.g. 5kg, Per Pack, Bottle)</label>
              <input type="text" name="unit" class="fctrl" value="<?= sanitize($product['unit']) ?>" required>
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">SRP (0 = TBD)</label>
              <input type="number" step="0.01" min="0" name="srp" class="fctrl" value="<?= sanitize($product['srp']) ?>" required>
            </div>
          </div>
          <div class="mb-3">
            <?php if ($product['image']): ?>
              <img src="<?= UPLOAD_URL ?>basics_products/<?= sanitize($product['image']) ?>" alt="" class="product-thumb mb-2 d-block" style="width:80px;height:80px;">
              <div class="form-check mb-2">
                <input type="checkbox" class="form-check-input" id="remove_image" name="remove_image" value="1">
                <label class="form-check-label" for="remove_image">Remove current image</label>
              </div>
            <?php endif; ?>
            <label class="flbl">Product Image</label>
            <input type="file" name="image" class="fctrl" accept=".jpg,.jpeg,.png,.webp">
            <div class="form-text">JPG, PNG, or WEBP, max 5MB. Leave blank to keep current image.</div>
          </div>
          <div class="mb-3">
            <label class="flbl">Status</label>
            <select name="status" class="fctrl">
              <option value="active" <?= $product['status'] === 'active' ? 'selected' : '' ?>>Active</option>
              <option value="inactive" <?= $product['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            </select>
          </div>

          <h2 class="h6 mb-3 mt-2">Catalog Placement</h2>
          <div class="form-check mb-3">
            <input type="checkbox" class="form-check-input" id="is_featured" name="is_featured" value="1" <?= $product['is_featured'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_featured">Featured Product — shows in the Featured row at the top of the catalog</label>
          </div>
          <div class="row">
            <div class="col-sm-6 mb-3">
              <label class="flbl">Flash Deal Price (optional)</label>
              <input type="number" step="0.01" min="0" name="flash_deal_price" class="fctrl" value="<?= $product['flash_deal_price'] !== null ? sanitize($product['flash_deal_price']) : '' ?>" placeholder="Leave blank for no deal">
            </div>
            <div class="col-sm-6 mb-3">
              <label class="flbl">Flash Deal Ends At</label>
              <input type="datetime-local" name="flash_deal_ends_at" class="fctrl" value="<?= $product['flash_deal_ends_at'] ? date('Y-m-d\TH:i', strtotime($product['flash_deal_ends_at'])) : '' ?>">
            </div>
          </div>
          <div class="form-text mb-3">Set both fields to run a Flash Deal — it shows at the top of the catalog with a countdown until the end time, then automatically stops showing (the fields aren't cleared, so re-running the same deal is just picking a new end time).</div>

          <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i>Save Product</button>
          <a href="<?= BASE_URL ?>/basics/admin/products.php" class="btn-outline-theme">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
