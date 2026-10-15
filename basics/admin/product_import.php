<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/spreadsheet.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

// Bulk add/update products from an Excel file, CSV, or Google Sheets link.
// Step 1 reads the sheet and shows a preview (kept in the session); step 2
// writes it. Rows are matched to existing products by SKU — a known SKU is
// updated, a new one is added. Photos aren't imported; add them per product.

const PRODUCT_IMPORT_MAX_ROWS = 2000;

// Header text (lowercased, punctuation stripped) => field. The first row
// that has any text is the header row; columns can be in any order.
$header_aliases = [
    'sku' => ['sku', 'code', 'product code', 'item code', 'barcode'],
    'category' => ['category', 'categories'],
    'name' => ['name', 'product', 'product name', 'item', 'item name', 'description'],
    'unit' => ['unit', 'uom', 'unit of measure', 'size', 'packaging'],
    'srp' => ['srp', 'price', 'selling price', 'retail price', 'unit price'],
    'status' => ['status', 'active'],
    'featured' => ['featured', 'is featured'],
];
$required_columns = ['sku', 'category', 'name', 'unit', 'srp'];

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="tindago_products_template.csv"');
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, ['SKU', 'Category', 'Name', 'Unit', 'SRP', 'Status', 'Featured'], ',', '"', '');
    fputcsv($out, ['RICE-001', 'Rice', 'Dinorado Rice', '5kg', '320.00', 'active', 'no'], ',', '"', '');
    fputcsv($out, ['BEV-001', 'Beverages', 'Coffee 3-in-1 (10 sachets)', 'Per Pack', '85.50', 'active', 'yes'], ',', '"', '');
    fclose($out);
    exit;
}

function product_import_header_key($text) {
    return trim(preg_replace('/[^a-z0-9]+/', ' ', strtolower($text)));
}

// Yes/no cells: TRUE/FALSE from Excel checkboxes, or typed words.
function product_import_flag($text) {
    $text = strtolower(trim($text));
    if ($text === '') return null;
    if (in_array($text, ['1', 'yes', 'y', 'true', 'active', 'featured'], true)) return 1;
    if (in_array($text, ['0', 'no', 'n', 'false', 'inactive'], true)) return 0;
    return false;
}

// Turns sheet rows into preview rows: [line, sku, category, name, unit,
// srp, status, is_featured, action ('new'|'update'|'error'), error].
function product_import_parse($conn, $rows, $header_aliases, $required_columns, &$errors) {
    $blank_top_rows = 0;
    while ($rows && implode('', $rows[0]) === '') {
        array_shift($rows);
        $blank_top_rows++;
    }
    if (!$rows) {
        $errors[] = 'The sheet is empty.';
        return [];
    }

    $columns = [];
    foreach ($rows[0] as $i => $heading) {
        $key = product_import_header_key($heading);
        foreach ($header_aliases as $field => $aliases) {
            if (!isset($columns[$field]) && in_array($key, $aliases, true)) {
                $columns[$field] = $i;
            }
        }
    }
    $missing = array_diff($required_columns, array_keys($columns));
    if ($missing) {
        $errors[] = 'The first row must be column headings. Missing: ' . implode(', ', array_map('strtoupper', $missing))
                  . '. Download the template to see the expected layout.';
        return [];
    }

    $existing = [];
    foreach ($conn->query("SELECT sku FROM basics_products")->fetch_all(MYSQLI_ASSOC) as $p) {
        $existing[strtolower($p['sku'])] = true;
    }
    $categories = basics_product_categories($conn);

    $parsed = [];
    $seen = [];
    foreach (array_slice($rows, 1, null, true) as $index => $row) {
        if (implode('', $row) === '') continue;
        $cell = fn($field) => isset($columns[$field]) ? trim($row[$columns[$field]] ?? '') : '';

        $item = [
            'line' => $blank_top_rows + $index + 1,
            'sku' => $cell('sku'),
            'category' => preg_replace('/\s+/', ' ', $cell('category')),
            'name' => $cell('name'),
            'unit' => $cell('unit'),
            'srp' => 0.0,
            'status' => null,
            'is_featured' => null,
            'error' => null,
        ];
        // Reuse an existing category typed with different capitalisation.
        foreach ($categories as $cat) {
            if (strcasecmp($cat, $item['category']) === 0) $item['category'] = $cat;
        }

        $row_errors = [];
        $srp_text = trim(str_ireplace(['₱', 'php', ','], '', $cell('srp')));
        if ($srp_text === '' || strtoupper($srp_text) === 'TBD') {
            $item['srp'] = 0.0;
        } elseif (is_numeric($srp_text) && (float) $srp_text >= 0) {
            $item['srp'] = round((float) $srp_text, 2);
        } else {
            $row_errors[] = 'SRP "' . $cell('srp') . '" isn\'t a price';
        }
        $status = product_import_flag($cell('status'));
        if ($status === false) $row_errors[] = 'Status should be active or inactive';
        else $item['status'] = $status === null ? null : ($status ? 'active' : 'inactive');
        $featured = product_import_flag($cell('featured'));
        if ($featured === false) $row_errors[] = 'Featured should be yes or no';
        else $item['is_featured'] = $featured;

        if ($item['sku'] === '') $row_errors[] = 'SKU is blank';
        elseif (mb_strlen($item['sku']) > 20) $row_errors[] = 'SKU is longer than 20 characters';
        elseif (isset($seen[strtolower($item['sku'])])) $row_errors[] = 'Same SKU as row ' . $seen[strtolower($item['sku'])];
        if ($item['category'] === '') $row_errors[] = 'Category is blank';
        elseif (mb_strlen($item['category']) > 100) $row_errors[] = 'Category is longer than 100 characters';
        if ($item['name'] === '') $row_errors[] = 'Name is blank';
        elseif (mb_strlen($item['name']) > 150) $row_errors[] = 'Name is longer than 150 characters';
        if ($item['unit'] === '') $row_errors[] = 'Unit is blank';
        elseif (mb_strlen($item['unit']) > 50) $row_errors[] = 'Unit is longer than 50 characters';

        if ($item['sku'] !== '' && !isset($seen[strtolower($item['sku'])])) {
            $seen[strtolower($item['sku'])] = $item['line'];
        }
        if ($row_errors) {
            $item['action'] = 'error';
            $item['error'] = implode('; ', $row_errors);
        } else {
            $item['action'] = isset($existing[strtolower($item['sku'])]) ? 'update' : 'new';
        }
        $item['new_category'] = $item['action'] !== 'error' && !in_array($item['category'], $categories, true);
        $parsed[] = $item;

        if (count($parsed) > PRODUCT_IMPORT_MAX_ROWS) {
            $errors[] = 'That sheet has more than ' . PRODUCT_IMPORT_MAX_ROWS . ' products. Split it into smaller files.';
            return [];
        }
    }
    if (!$parsed) {
        $errors[] = 'No products found below the heading row.';
    }
    return $parsed;
}

$errors = [];
$preview = null;
$source_label = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'read') {
    $sheet_url = trim($_POST['sheet_url'] ?? '');
    $rows = [];
    $read_error = null;
    if (!empty($_FILES['sheet']['name'])) {
        $file = $_FILES['sheet'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $read_error = 'The upload failed. Try again.';
        } elseif ($file['size'] > TINDAGO_SHEET_MAX_BYTES) {
            $read_error = 'That file is over 5MB.';
        } elseif ($ext === 'xlsx') {
            [$rows, $read_error] = tindago_sheet_rows_from_xlsx($file['tmp_name']);
        } elseif ($ext === 'csv') {
            [$rows, $read_error] = tindago_sheet_rows_from_csv(file_get_contents($file['tmp_name']));
        } else {
            $read_error = 'Upload an Excel (.xlsx) or CSV file. For older .xls files, open them and save as .xlsx first.';
        }
        $source_label = $file['name'];
    } elseif ($sheet_url !== '') {
        [$rows, $read_error] = tindago_sheet_rows_from_google($sheet_url);
        $source_label = 'Google Sheet';
    } else {
        $read_error = 'Choose a file or paste a Google Sheets link.';
    }

    if ($read_error) {
        $errors[] = $read_error;
    } else {
        $preview = product_import_parse($conn, $rows, $header_aliases, $required_columns, $errors);
        if ($preview) {
            $_SESSION['product_import'] = ['rows' => $preview, 'source' => $source_label];
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'confirm') {
    $pending = $_SESSION['product_import'] ?? null;
    if (!$pending) {
        redirect('/basics/admin/product_import.php');
    }
    $added = 0;
    $updated = 0;
    $conn->begin_transaction();
    try {
        $categories = basics_product_categories($conn);
        foreach ($pending['rows'] as $item) {
            if ($item['action'] === 'error') continue;

            if (!in_array($item['category'], $categories, true)) {
                $stmt = $conn->prepare("INSERT INTO basics_product_categories (name, sort_order)
                                         SELECT ?, COALESCE(MAX(sort_order), 0) + 1 FROM basics_product_categories");
                $stmt->bind_param('s', $item['category']);
                $stmt->execute();
                $stmt->close();
                $categories[] = $item['category'];
                log_activity($conn, 'create_basics_product_category', 'Added product category "' . $item['category'] . '" (product import)');
            }

            // Looked up again here, not trusted from the preview, in case the
            // product was added or deleted in between.
            $stmt = $conn->prepare("SELECT id, status, is_featured FROM basics_products WHERE sku = ?");
            $stmt->bind_param('s', $item['sku']);
            $stmt->execute();
            $current = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            // A blank Status/Featured cell keeps what the product already has.
            if ($current) {
                $status = $item['status'] ?? $current['status'];
                $is_featured = $item['is_featured'] ?? (int) $current['is_featured'];
                $stmt = $conn->prepare("UPDATE basics_products SET category=?, name=?, unit=?, srp=?, status=?, is_featured=? WHERE id=?");
                $stmt->bind_param('sssdsii', $item['category'], $item['name'], $item['unit'], $item['srp'], $status, $is_featured, $current['id']);
                $stmt->execute();
                $stmt->close();
                $updated++;
            } else {
                $status = $item['status'] ?? 'active';
                $is_featured = $item['is_featured'] ?? 0;
                $stmt = $conn->prepare("INSERT INTO basics_products (sku, category, name, unit, srp, status, is_featured) VALUES (?,?,?,?,?,?,?)");
                $stmt->bind_param('ssssdsi', $item['sku'], $item['category'], $item['name'], $item['unit'], $item['srp'], $status, $is_featured);
                $stmt->execute();
                $stmt->close();
                $added++;
            }
        }
        $conn->commit();
    } catch (mysqli_sql_exception $e) {
        $conn->rollback();
        error_log('Product import failed: ' . $e->getMessage());
        $errors[] = 'The import failed and nothing was changed. Please try again.';
        $preview = $pending['rows'];
        $source_label = $pending['source'];
    }

    if (!$errors) {
        unset($_SESSION['product_import']);
        log_activity($conn, 'import_basics_products', 'Imported products from ' . $pending['source'] . ": $added added, $updated updated");
        redirect('/basics/admin/products.php?imported=' . $added . '&updated=' . $updated);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
    unset($_SESSION['product_import']);
    redirect('/basics/admin/product_import.php');
}

$counts = ['new' => 0, 'update' => 0, 'error' => 0];
foreach ($preview ?? [] as $item) {
    $counts[$item['action']]++;
}
$new_categories = array_unique(array_column(array_filter($preview ?? [], fn($i) => $i['new_category']), 'category'));

$page_title = 'Import Products';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <a href="<?= BASE_URL ?>/basics/admin/products.php" class="small">&larr; Back to Products</a>
    <h1 class="stitle" style="font-size:2rem;">Import Products</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if ($errors): ?>
    <div class="errmsg">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <?php if ($preview): ?>
    <div class="panel-card mb-4">
      <h2 class="h6 mb-2">Preview — <?= sanitize($source_label) ?></h2>
      <p class="mb-3">
        <span class="pill pill-completed"><?= $counts['new'] ?> new</span>
        <span class="pill pill-approved"><?= $counts['update'] ?> update<?= $counts['update'] === 1 ? '' : 's' ?></span>
        <?php if ($counts['error']): ?><span class="pill pill-rejected"><?= $counts['error'] ?> with problems (will be skipped)</span><?php endif; ?>
      </p>
      <?php if ($new_categories): ?>
        <p class="small mb-3">New categories that will be created: <strong><?= sanitize(implode(', ', $new_categories)) ?></strong></p>
      <?php endif; ?>
      <p class="small text-muted mb-3">Products are matched by SKU: an existing SKU is updated with the sheet's values, a new SKU is added. Blank Status/Featured cells keep the current setting (new products default to active, not featured). Photos aren't imported.</p>
      <div class="d-flex gap-2 flex-wrap">
        <?php if ($counts['new'] + $counts['update'] > 0): ?>
          <form method="post">
            <input type="hidden" name="action" value="confirm">
            <button type="submit" class="btn-red"><i class="fas fa-file-import"></i>Import <?= $counts['new'] + $counts['update'] ?> Product<?= $counts['new'] + $counts['update'] === 1 ? '' : 's' ?></button>
          </form>
        <?php endif; ?>
        <form method="post">
          <input type="hidden" name="action" value="cancel">
          <button type="submit" class="btn-outline-theme">Cancel</button>
        </form>
      </div>
    </div>

    <div class="table-responsive">
      <table class="table-theme">
        <thead><tr><th>Row</th><th>Result</th><th>SKU</th><th>Category</th><th>Name</th><th>Unit</th><th>SRP</th><th>Status</th><th>Featured</th></tr></thead>
        <tbody>
        <?php foreach ($preview as $item): ?>
          <tr>
            <td><?= (int) $item['line'] ?></td>
            <td>
              <?php if ($item['action'] === 'error'): ?>
                <span class="pill pill-rejected">Skipped</span><div class="small text-danger"><?= sanitize($item['error']) ?></div>
              <?php elseif ($item['action'] === 'update'): ?>
                <span class="pill pill-approved">Update</span>
              <?php else: ?>
                <span class="pill pill-completed">New</span>
              <?php endif; ?>
            </td>
            <td><code><?= sanitize($item['sku']) ?></code></td>
            <td><?= sanitize($item['category']) ?><?php if ($item['new_category']): ?> <span class="small text-muted">(new)</span><?php endif; ?></td>
            <td><?= sanitize($item['name']) ?></td>
            <td><?= sanitize($item['unit']) ?></td>
            <td><?= $item['srp'] > 0 ? format_price($item['srp']) : '<span class="text-muted">TBD</span>' ?></td>
            <td><?= $item['status'] !== null ? sanitize($item['status']) : '<span class="text-muted">—</span>' ?></td>
            <td><?= $item['is_featured'] === null ? '<span class="text-muted">—</span>' : ($item['is_featured'] ? 'Yes' : 'No') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php else: ?>
    <div class="row g-4">
      <div class="col-lg-7">
        <div class="panel-card">
          <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="action" value="read">
            <div class="mb-3">
              <label class="flbl">Excel or CSV file</label>
              <input type="file" name="sheet" class="fctrl" accept=".xlsx,.csv">
              <div class="form-text">.xlsx or .csv, max 5MB. Only the first sheet (tab) is read.</div>
            </div>
            <p class="text-muted small mb-3">— or —</p>
            <div class="mb-3">
              <label class="flbl">Google Sheets link</label>
              <input type="url" name="sheet_url" class="fctrl" placeholder="https://docs.google.com/spreadsheets/d/…" value="<?= sanitize($_POST['sheet_url'] ?? '') ?>">
              <div class="form-text">Set sharing to "Anyone with the link" (Viewer) first. The tab that was open when you copied the link is read.</div>
            </div>
            <button type="submit" class="btn-red"><i class="fas fa-eye"></i>Preview Import</button>
            <a href="<?= BASE_URL ?>/basics/admin/products.php" class="btn-outline-theme">Cancel</a>
          </form>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="panel-card">
          <h2 class="h6 mb-2">Sheet format</h2>
          <p class="small mb-2">The first row must be headings. Columns can be in any order; other columns are ignored.</p>
          <ul class="small mb-3">
            <li><strong>SKU</strong> — required, up to 20 characters. Matches existing products.</li>
            <li><strong>Category</strong> — required. New names become new categories.</li>
            <li><strong>Name</strong> — required.</li>
            <li><strong>Unit</strong> — required, e.g. 5kg, Per Pack, Bottle.</li>
            <li><strong>SRP</strong> — price; blank or TBD = 0 (TBD).</li>
            <li><strong>Status</strong> — optional: active / inactive.</li>
            <li><strong>Featured</strong> — optional: yes / no.</li>
          </ul>
          <a href="<?= BASE_URL ?>/basics/admin/product_import.php?template=1" class="btn-outline-theme"><i class="fas fa-download"></i>Download Template</a>
        </div>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
