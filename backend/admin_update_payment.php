<?php
require __DIR__.'/config.php';
require_admin();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
http_response_code(403);
echo json_encode(['success'=>false,'message'=>'Payment verification is handled by the assigned cashier. Admin has read-only payment monitoring access.']);
