<?php
require __DIR__ . '/../config/constants.php';
require __DIR__ . '/../config/database.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/auth.php';

require_admin_login();

$id = (int) ($_GET['id'] ?? 0);
$product = [
    'id' => 0, 'name' => '', 'description' => '', 'srp' => '', 'image' => null, 'status' => 'active',
];
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $product = $stmt->get_result()->fetch_assoc() ?: $product;
    $stmt->close();
}

$errors = [];

// "Write with AI" — called by fetch() from the Description field. Uses the
// photo just picked in the form if there is one (not saved yet), otherwise
// the product's saved image. Only returns text for the admin to edit.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'ai_description') {
    $name = trim($_POST['name'] ?? '');
    $image_path = null;
    if (!empty($_FILES['ai_image']['tmp_name']) && $_FILES['ai_image']['error'] === UPLOAD_ERR_OK) {
        $image_path = $_FILES['ai_image']['tmp_name'];
    } elseif ($product['image'] && is_file(UPLOAD_PATH . 'products/' . $product['image'])) {
        $image_path = UPLOAD_PATH . 'products/' . $product['image'];
    }

    header('Content-Type: application/json');
    if ($name === '' && !$image_path) {
        echo json_encode(['success' => false, 'error' => 'Enter a product name or choose a photo first.']);
    } else {
        echo json_encode(ai_write_product_description($name, $image_path));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add_highlight' && $id) {
    [$new_image, $upload_error] = handle_product_image_upload('highlight_image', null);
    if ($upload_error) {
        $errors[] = $upload_error;
    } elseif ($new_image) {
        $next_order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), -1) + 1 AS n FROM product_images WHERE product_id = $id")->fetch_assoc()['n'];
        $stmt = $conn->prepare("INSERT INTO product_images (product_id, image, sort_order) VALUES (?, ?, ?)");
        $stmt->bind_param('isi', $id, $new_image, $next_order);
        $stmt->execute();
        $stmt->close();
        redirect('/admin/product_edit.php?id=' . $id);
    } else {
        $errors[] = 'Choose an image to add.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_highlight' && $id) {
    $image_id = (int) ($_POST['image_id'] ?? 0);
    $stmt = $conn->prepare("SELECT image FROM product_images WHERE id = ? AND product_id = ?");
    $stmt->bind_param('ii', $image_id, $id);
    $stmt->execute();
    $highlight = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($highlight) {
        $stmt = $conn->prepare("DELETE FROM product_images WHERE id = ?");
        $stmt->bind_param('i', $image_id);
        $stmt->execute();
        $stmt->close();
        if (is_file(UPLOAD_PATH . 'products/' . $highlight['image'])) {
            unlink(UPLOAD_PATH . 'products/' . $highlight['image']);
        }
    }
    redirect('/admin/product_edit.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $srp = (float) ($_POST['srp'] ?? 0);
    $status = ($_POST['status'] ?? 'active') === 'inactive' ? 'inactive' : 'active';
    $image = $product['image'];

    if ($name === '') $errors[] = 'Product name is required.';
    if ($srp <= 0) $errors[] = 'SRP must be greater than 0.';

    [$image, $image_error] = handle_product_image_upload('image', $image);
    if ($image_error) $errors[] = $image_error;

    if (empty($errors)) {
        if ($id) {
            $stmt = $conn->prepare("UPDATE products SET name=?, description=?, srp=?, image=?, status=? WHERE id=?");
            $stmt->bind_param('ssdssi', $name, $description, $srp, $image, $status, $id);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'update_product', 'Updated Wellness product "' . $name . '"');
            redirect('/admin/product_edit.php?id=' . $id . '&saved=1');
        } else {
            $stmt = $conn->prepare("INSERT INTO products (name, description, srp, image, status) VALUES (?,?,?,?,?)");
            $stmt->bind_param('ssdss', $name, $description, $srp, $image, $status);
            $stmt->execute();
            $new_id = $stmt->insert_id;
            $stmt->close();
            log_activity($conn, 'create_product', 'Created Wellness product "' . $name . '"');
            redirect('/admin/product_edit.php?id=' . $new_id . '&saved=1');
        }
    }
    $product = array_merge($product, compact('name', 'description', 'srp', 'status', 'image'));
}

$highlights = null;
if ($id) {
    $stmt = $conn->prepare("SELECT * FROM product_images WHERE product_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $highlights = $stmt->get_result();
}

$page_title = $id ? 'Edit Product' : 'Add Product';
require __DIR__ . '/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/admin/products.php" class="small">&larr; Back to Products</a>
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
    <div class="col-12 col-lg-7">
      <div class="panel-card">
        <form method="post" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save">
          <div class="mb-3">
            <label class="flbl">Product Name</label>
            <input type="text" name="name" class="fctrl" value="<?= sanitize($product['name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Description</label>
            <textarea name="description" id="descriptionField" class="fctrl" rows="3"><?= sanitize($product['description']) ?></textarea>
            <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
              <button type="button" class="btn-chip btn-chip-outline" id="aiDescriptionBtn" onclick="aiWriteDescription();"><i class="fas fa-wand-magic-sparkles"></i> Write with AI</button>
              <span id="aiDescriptionStatus" class="small text-muted">Uses the product name and photo. Review it before saving.</span>
            </div>
          </div>
          <div class="mb-3">
            <label class="flbl">SRP (Suggested Retail Price)</label>
            <input type="number" step="0.01" min="0" name="srp" class="fctrl" value="<?= sanitize($product['srp']) ?>" required>
          </div>
          <div class="mb-3">
            <?php if ($product['image']): ?>
              <img src="<?= UPLOAD_URL ?>products/<?= sanitize($product['image']) ?>" alt="" class="product-thumb mb-2" style="width:80px;height:80px;">
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
          <a href="<?= BASE_URL ?>/admin/products.php" class="btn-outline-theme">Cancel</a>
        </form>
      </div>
    </div>

    <div class="col-12 col-lg-5">
      <div class="panel-card">
        <h2 class="h6 mb-3">Highlight Images</h2>
        <?php if (!$id): ?>
          <p class="small text-muted mb-0">Save the product first, then you can add highlight images here.</p>
        <?php else: ?>
          <div class="row g-2 mb-3">
            <?php if ($highlights->num_rows === 0): ?>
              <p class="small text-muted">No highlight images yet.</p>
            <?php endif; ?>
            <?php while ($h = $highlights->fetch_assoc()): ?>
              <div class="col-6">
                <div class="position-relative">
                  <img src="<?= UPLOAD_URL ?>products/<?= sanitize($h['image']) ?>" alt="" class="w-100 rounded" style="height:110px;object-fit:cover;">
                  <form method="post" class="position-absolute top-0 end-0 m-1">
                    <input type="hidden" name="action" value="delete_highlight">
                    <input type="hidden" name="image_id" value="<?= (int) $h['id'] ?>">
                    <button type="submit" class="btn-chip btn-chip-primary" style="padding:2px 8px;" onclick="return confirm('Remove this highlight image?');"><i class="fas fa-trash"></i></button>
                  </form>
                </div>
              </div>
            <?php endwhile; ?>
          </div>
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="add_highlight">
            <label class="flbl">Add Highlight Image</label>
            <input type="file" name="highlight_image" class="fctrl mb-2" accept=".jpg,.jpeg,.png,.webp" required>
            <div class="form-text mb-2">Shown in a "Product Highlights" gallery on the product page. JPG, PNG, or WEBP, max 5MB.</div>
            <button type="submit" class="btn-outline-theme w-100 justify-content-center">Add Image</button>
          </form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<script>
function aiWriteDescription() {
  var form = document.getElementById('descriptionField').form;
  var status = document.getElementById('aiDescriptionStatus');
  var button = document.getElementById('aiDescriptionBtn');
  var body = new FormData();
  body.append('action', 'ai_description');
  body.append('name', form.elements.name.value.trim());
  if (form.elements.image.files[0]) body.append('ai_image', form.elements.image.files[0]);

  button.disabled = true;
  status.textContent = 'Writing…';
  fetch(window.location.href, { method: 'POST', body: body, credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      if (!data.success) {
        status.textContent = data.error;
        return;
      }
      document.getElementById('descriptionField').value = data.description;
      status.textContent = 'Draft ready — edit it if needed, then Save Product.';
    })
    .catch(function () { status.textContent = 'Could not reach the AI. Please try again.'; })
    .finally(function () { button.disabled = false; });
}
</script>
<?php require __DIR__ . '/includes/admin_footer.php'; ?>
