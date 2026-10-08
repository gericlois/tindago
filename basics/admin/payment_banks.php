<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';
require __DIR__ . '/../includes/functions.php';

require_basics_admin_role(['super_admin', 'admin']);

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_bank') {
        $name = trim($_POST['name'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');

        if ($name === '') $errors[] = 'Bank name is required.';
        if ($account_name === '') $errors[] = 'Account name is required.';
        if ($account_number === '') $errors[] = 'Account number is required.';

        [$qr_filename, $upload_error] = handle_payment_bank_qr_upload('qr_image');
        if ($upload_error) $errors[] = $upload_error;

        if (empty($errors)) {
            $next_order = (int) $conn->query("SELECT COALESCE(MAX(sort_order), 0) + 1 AS next_order FROM basics_payment_banks")->fetch_assoc()['next_order'];

            $stmt = $conn->prepare("INSERT INTO basics_payment_banks (name, account_name, account_number, qr_image, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->bind_param('ssssi', $name, $account_name, $account_number, $qr_filename, $next_order);
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'add_basics_payment_bank', 'Added payment bank "' . $name . '"');
            redirect('/basics/admin/payment_banks.php?added=1');
        }
    } elseif ($action === 'update_bank') {
        $bank_id = (int) ($_POST['bank_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $account_name = trim($_POST['account_name'] ?? '');
        $account_number = trim($_POST['account_number'] ?? '');

        if ($name === '') $errors[] = 'Bank name is required.';
        if ($account_name === '') $errors[] = 'Account name is required.';
        if ($account_number === '') $errors[] = 'Account number is required.';

        [$qr_filename, $upload_error] = handle_payment_bank_qr_upload('qr_image');
        if ($upload_error) $errors[] = $upload_error;

        if (empty($errors)) {
            if ($qr_filename) {
                $stmt = $conn->prepare("UPDATE basics_payment_banks SET name = ?, account_name = ?, account_number = ?, qr_image = ? WHERE id = ?");
                $stmt->bind_param('ssssi', $name, $account_name, $account_number, $qr_filename, $bank_id);
            } else {
                $stmt = $conn->prepare("UPDATE basics_payment_banks SET name = ?, account_name = ?, account_number = ? WHERE id = ?");
                $stmt->bind_param('sssi', $name, $account_name, $account_number, $bank_id);
            }
            $stmt->execute();
            $stmt->close();
            log_activity($conn, 'update_basics_payment_bank', 'Updated payment bank #' . $bank_id . ' (' . $name . ')');
            redirect('/basics/admin/payment_banks.php?updated=1');
        }
    } elseif ($action === 'toggle_enabled') {
        $bank_id = (int) ($_POST['bank_id'] ?? 0);
        $stmt = $conn->prepare("UPDATE basics_payment_banks SET is_enabled = NOT is_enabled WHERE id = ?");
        $stmt->bind_param('i', $bank_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'toggle_basics_payment_bank', 'Toggled enabled status for payment bank #' . $bank_id);
        redirect('/basics/admin/payment_banks.php');
    } elseif ($action === 'delete_bank') {
        $bank_id = (int) ($_POST['bank_id'] ?? 0);
        $stmt = $conn->prepare("DELETE FROM basics_payment_banks WHERE id = ?");
        $stmt->bind_param('i', $bank_id);
        $stmt->execute();
        $stmt->close();
        log_activity($conn, 'delete_basics_payment_bank', 'Deleted payment bank #' . $bank_id);
        redirect('/basics/admin/payment_banks.php?deleted=1');
    }
}

$banks = $conn->query("SELECT * FROM basics_payment_banks ORDER BY sort_order ASC, name ASC")->fetch_all(MYSQLI_ASSOC);

$page_title = 'Payment Banks';
require __DIR__ . '/../../admin/includes/admin_header.php';
require __DIR__ . '/includes/admin_sidebar.php';
?>
<div class="inner-hero">
  <div class="container">
    <span class="slbl">Manage</span>
    <h1 class="stitle" style="font-size:2rem;">Payment Banks</h1>
  </div>
</div>

<div class="container-fluid py-4">
  <?php if (isset($_GET['added'])): ?><div class="sucmsg is-visible mb-4"><p class="mb-0">Payment bank added.</p></div><?php endif; ?>
  <?php if (isset($_GET['updated'])): ?><div class="sucmsg is-visible mb-4"><p class="mb-0">Payment bank updated.</p></div><?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?><div class="sucmsg is-visible mb-4"><p class="mb-0">Payment bank deleted.</p></div><?php endif; ?>
  <?php if ($errors): ?>
    <div class="errmsg mb-4">
      <ul class="mb-0"><?php foreach ($errors as $error): ?><li><?= sanitize($error) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <div class="form-text mb-3">Members choose from these when submitting a payment — only enabled banks appear on their Payments page.</div>

  <div class="d-flex justify-content-end mb-3">
    <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#addBankModal"><i class="fas fa-plus"></i> Add Payment Bank</button>
  </div>

  <div class="table-responsive">
    <table class="table-theme">
      <thead><tr><th>Name</th><th>Account Name</th><th>Account Number</th><th>QR</th><th>Status</th><th class="no-print"></th></tr></thead>
      <tbody>
      <?php if (empty($banks)): ?>
        <tr><td colspan="6" class="text-muted">No payment banks yet.</td></tr>
      <?php endif; ?>
      <?php foreach ($banks as $b): ?>
        <tr>
          <td><?= sanitize($b['name']) ?></td>
          <td><?= sanitize($b['account_name']) ?></td>
          <td><?= sanitize($b['account_number']) ?></td>
          <td>
            <?php if ($b['qr_image']): ?>
              <img src="<?= BASE_URL ?>/uploads/basics_payment_bank_qrs/<?= sanitize($b['qr_image']) ?>" alt="<?= sanitize($b['name']) ?> QR" style="width:40px;height:40px;object-fit:cover;border-radius:4px;">
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="pill pill-<?= $b['is_enabled'] ? 'active' : 'inactive' ?>"><?= $b['is_enabled'] ? 'Enabled' : 'Disabled' ?></span></td>
          <td class="no-print">
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="toggle_enabled">
              <input type="hidden" name="bank_id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="btn-chip btn-chip-outline"><?= $b['is_enabled'] ? 'Disable' : 'Enable' ?></button>
            </form>
            <button type="button" class="btn-chip btn-chip-success" data-bs-toggle="modal" data-bs-target="#editBankModal-<?= (int) $b['id'] ?>">Edit</button>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="delete_bank">
              <input type="hidden" name="bank_id" value="<?= (int) $b['id'] ?>">
              <button type="submit" class="btn-chip btn-chip-outline" onclick="return confirm('Delete this payment bank? Past payment submissions keep their own record and are not affected.');">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="addBankModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="add_bank">
        <div class="modal-header">
          <h5 class="modal-title">Add Payment Bank</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="flbl">Bank / Wallet Name</label>
            <input type="text" name="name" class="fctrl" placeholder="e.g. GCash, BDO, Maya" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account Name</label>
            <input type="text" name="account_name" class="fctrl" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account Number</label>
            <input type="text" name="account_number" class="fctrl" required>
          </div>
          <div class="mb-3">
            <label class="flbl">QR Code (optional)</label>
            <input type="file" name="qr_image" class="fctrl" accept=".jpg,.jpeg,.png,.webp">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-chip btn-chip-success">Add Bank</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php foreach ($banks as $b): ?>
<div class="modal fade" id="editBankModal-<?= (int) $b['id'] ?>" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" enctype="multipart/form-data">
        <input type="hidden" name="action" value="update_bank">
        <input type="hidden" name="bank_id" value="<?= (int) $b['id'] ?>">
        <div class="modal-header">
          <h5 class="modal-title">Edit — <?= sanitize($b['name']) ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="flbl">Bank / Wallet Name</label>
            <input type="text" name="name" class="fctrl" value="<?= sanitize($b['name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account Name</label>
            <input type="text" name="account_name" class="fctrl" value="<?= sanitize($b['account_name']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">Account Number</label>
            <input type="text" name="account_number" class="fctrl" value="<?= sanitize($b['account_number']) ?>" required>
          </div>
          <div class="mb-3">
            <label class="flbl">QR Code<?= $b['qr_image'] ? ' (leave blank to keep the current one)' : ' (optional)' ?></label>
            <?php if ($b['qr_image']): ?>
              <div class="mb-2"><img src="<?= BASE_URL ?>/uploads/basics_payment_bank_qrs/<?= sanitize($b['qr_image']) ?>" alt="Current QR" style="width:80px;height:80px;object-fit:cover;border-radius:4px;"></div>
            <?php endif; ?>
            <input type="file" name="qr_image" class="fctrl" accept=".jpg,.jpeg,.png,.webp">
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn-chip btn-chip-outline" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn-chip btn-chip-success">Save Changes</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endforeach; ?>

<?php require __DIR__ . '/../../admin/includes/admin_footer.php'; ?>
