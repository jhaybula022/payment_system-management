<?php
declare(strict_types=1);

// Return JSON for the metadata request, but stream the actual image when view=1.
// This endpoint intentionally avoids running the table-creation/migration helpers;
// the payment page already works only when the payments table exists, and the proof
// viewer should not fail because an old database needs a migration.

require __DIR__ . '/config.php';

try {
    $s = require_login();
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'Invalid payment record.']);
        exit;
    }

    $q = $pdo->prepare("SELECT id, user_id, proof_file_name, payment_method, reference_number FROM payments WHERE id = ? LIMIT 1");
    $q->execute([$id]);
    $p = $q->fetch();

    if (!$p) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Payment record not found.']);
        exit;
    }

    // Resolve the current role from the authenticated database user, not from
    // a client-side/session flag. Admins can view every proof; students can only
    // view their own proof.
    $rq = $pdo->prepare('SELECT role, status FROM users WHERE id=? LIMIT 1');
    $rq->execute([(int)$s['user_id']]);
    $viewer = $rq->fetch() ?: [];
    $isAdminViewer = (($viewer['role'] ?? '') === 'admin' && ($viewer['status'] ?? '') === 'Active');
    $isCashierViewer = (($viewer['role'] ?? '') === 'cashier' && ($viewer['status'] ?? '') === 'Active');
    if ($isCashierViewer) {
        $cq=$pdo->prepare('SELECT 1 FROM payments WHERE id=? AND cashier_id=? LIMIT 1');
        $cq->execute([$id,(int)$s['user_id']]);
        $isCashierViewer=(bool)$cq->fetchColumn();
    }
    if (!$isAdminViewer && !$isCashierViewer && (int)$p['user_id'] !== (int)$s['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'You are not allowed to view this proof.']);
        exit;
    }

    $name = trim((string)($p['proof_file_name'] ?? ''));
    if ($name === '') {
        echo json_encode(['success' => true, 'file' => null, 'message' => 'No proof was uploaded for this transaction.']);
        exit;
    }

    $name = basename($name);
    $path = __DIR__ . '/uploads/payment_proofs/' . $name;
    if (!is_file($path) || !is_readable($path)) {
        echo json_encode([
            'success' => true,
            'file' => null,
            'message' => 'The payment record exists, but its uploaded proof image is not available on this XAMPP installation.'
        ]);
        exit;
    }

    $mime = '';
    if (function_exists('finfo_open')) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        if ($finfo) {
            $mime = (string)finfo_file($finfo, $path);
            finfo_close($finfo);
        }
    }
    if ($mime === '' && function_exists('mime_content_type')) {
        $mime = (string)mime_content_type($path);
    }

    $allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/avif'];
    if (!in_array($mime, $allowed, true)) {
        http_response_code(422);
        echo json_encode(['success' => false, 'message' => 'The stored proof is not a supported image.']);
        exit;
    }

    if (($_GET['view'] ?? '') === '1') {
        // Switch from JSON to the actual image response.
        header_remove('Content-Type');
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string)filesize($path));
        header('Content-Disposition: inline; filename="proof-' . (int)$p['id'] . '.' . strtolower(pathinfo($name, PATHINFO_EXTENSION)) . '"');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    echo json_encode([
        'success' => true,
        'file' => [
            'url' => 'backend/payment_proof.php?id=' . $id . '&view=1',
            'name' => $name,
            'mime' => $mime,
            'is_image' => true
        ]
    ]);
} catch (Throwable $e) {
    error_log('payment_proof.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Unable to load the payment proof. Check that Apache/PHP and MySQL are running.'
    ]);
}
