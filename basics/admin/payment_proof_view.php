<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_payments']);

$id = (int) ($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM basics_payment_submissions WHERE id = ?");
$stmt->bind_param('i', $id);
$stmt->execute();
$submission = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$submission || !$submission['proof_image']) {
    http_response_code(404);
    exit('Proof not found.');
}

$path = UPLOAD_PATH . 'basics_payment_proofs/' . $submission['proof_image'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found on disk.');
}

send_attachment_cache_headers($path);

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$content_types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . ($content_types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="payment-proof-' . $id . '.' . $extension . '"');
readfile($path);
