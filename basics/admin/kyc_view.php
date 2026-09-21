<?php
require __DIR__ . '/../../config/constants.php';
require __DIR__ . '/../../config/database.php';
require __DIR__ . '/../../includes/functions.php';
require __DIR__ . '/../../includes/auth.php';

require_basics_admin_role(['super_admin', 'admin', 'staff_orders']);

$doc_id = (int) ($_GET['doc_id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM basics_kyc_documents WHERE id = ?");
$stmt->bind_param('i', $doc_id);
$stmt->execute();
$doc = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$doc) {
    http_response_code(404);
    exit('Document not found.');
}

$path = UPLOAD_PATH . 'basics_kyc/' . $doc['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    exit('File not found on disk.');
}

send_attachment_cache_headers($path);

$extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
$content_types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'pdf' => 'application/pdf'];
header('Content-Type: ' . ($content_types[$extension] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . $doc['doc_type'] . '.' . $extension . '"');
readfile($path);
