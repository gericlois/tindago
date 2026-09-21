<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$id = (int) ($_GET['id'] ?? 0);
$product = [
    'id' => 0, 'sku' => '', 'category' => 'Rice', 'name' => '', 'unit' => '', 'srp' => '0', 'image' => null, 'status' => 'active',
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

    if ($sku === '') $errors[] = 'SKU is required.';
    if ($name === '') $errors[] = 'Product name is required.';
    if ($unit === '') $errors[] = 'Unit is required.';
    if ($srp < 0) $errors[] = 'SRP cannot be negative.';

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
            $stmt = $conn->prepare("UPDATE basics_products SET sku=?, category=?, name=?, unit=?, srp=?, image=?, status=? WHERE id=?");
            $stmt->bind_param('ssssdssi', $sku, $category, $name, $unit, $srp, $image, $status, $id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'update_basics_product', 'Updated Basics product "' . $name . '" (' . $sku . ')');
            redirect('/basics/admin/product_edit.php?id=' . $id . '&saved=1');
        } else {
            $stmt = $conn->prepare("INSERT INTO basics_products (sku, category, name, unit, srp, image, status) VALUES (?,?,?,?,?,?,?)");
            $stmt->bind_param('ssssdss', $sku, $category, $name, $unit, $srp, $image, $status);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            log_activity($conn, 'create_basics_product', 'Created Basics product "' . $name . '" (' . $sku . ')');
            redirect('/basics/admin/product_edit.php?id=' . $new_id . '&saved=1');
        }
    }
    $product = array_merge($product, compact('sku', 'category', 'name', 'unit', 'srp', 'status', 'image'));
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
          <button type="submit" class="btn-red"><i class="fas fa-floppy-disk"></i>Save Product</button>
          <a href="<?= BASE_URL ?>/basics/admin/products.php" class="btn-outline-theme">Cancel</a>
        </form>
      </div>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
