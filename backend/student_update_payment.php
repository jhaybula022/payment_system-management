<?php
require __DIR__.'/config.php';
$s = require_login();

try {
  ensure_payments_table($pdo);
  $in = json_decode(file_get_contents('php://input'), true) ?: [];

  $id = (int)($in['id'] ?? ($in['payment_id'] ?? 0));
  $amount = (float)str_replace(',', '', (string)($in['amount'] ?? 0));
  $method = trim((string)($in['payment_method'] ?? ''));
  $ref = trim((string)($in['reference_number'] ?? ''));

  $allowedMethods = ['GCash', 'Bank Transfer', 'Cash', 'Hello Money App (HMA)'];

  if ($id < 1 || $amount <= 0 || $method === '' || $ref === '') {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Payment ID, amount, payment method, and reference number are required.']);
    exit;
  }

  if (!in_array($method, $allowedMethods, true)) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Invalid payment method.']);
    exit;
  }

  // Resolve the real MySQL payment ID first, then enforce ownership.
  // This prevents a frontend/demo ID from being mistaken for the database ID.
  $q = $pdo->prepare("SELECT id, user_id, status, payment_channel FROM payments WHERE id=? LIMIT 1");
  $q->execute([$id]);
  $payment = $q->fetch();

  if (!$payment) {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Payment record not found in the database.']);
    exit;
  }

  if ((int)$payment['user_id'] !== (int)$s['user_id']) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'You can only edit your own payment records.']);
    exit;
  }

  // Confirmed ledger payments, including HelloMoney QR payments marked Paid,
  // cannot be edited by the student.
  if (in_array((string)$payment['status'], ['Verified','Paid'], true)) {
    http_response_code(409);
    echo json_encode(['success'=>false,'message'=>'Confirmed payments cannot be edited because they are already part of the official payment ledger.']);
    exit;
  }

  // Any change to an unconfirmed record requires admin verification again.
  $q = $pdo->prepare("UPDATE payments
                      SET amount=?, payment_method=?, reference_number=?,
                          status=CASE WHEN payment_channel='Face-to-Face' THEN 'Pending Verification' ELSE 'Pending Online Confirmation' END, verified_by=NULL, verified_at=NULL
                      WHERE id=? AND user_id=?");
  $q->execute([$amount, $method, $ref, $id, (int)$s['user_id']]);

  echo json_encode([
    'success'=>true,
    'message'=>'Payment information updated and returned to the appropriate payment queue.',
    'payment_id'=>$id,
    'status'=>((string)($payment['payment_channel']??'Online')==='Face-to-Face'?'Pending Verification':'Pending Online Confirmation')
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  error_log('student_update_payment.php: '.$e->getMessage());
  echo json_encode(['success'=>false,'message'=>'Payment could not be updated. Database error: '.$e->getMessage()]);
}
