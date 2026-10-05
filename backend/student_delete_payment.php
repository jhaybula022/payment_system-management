<?php
require __DIR__.'/config.php';
$s = require_login();

try {
  ensure_payments_table($pdo);
  $in = json_decode(file_get_contents('php://input'), true) ?: [];
  $id = (int)($in['id'] ?? ($in['payment_id'] ?? 0));

  if ($id < 1) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Payment ID is required.']);
    exit;
  }

  $q = $pdo->prepare("SELECT id,user_id,status,proof_file_name,reference_number,amount FROM payments WHERE id=? LIMIT 1");
  $q->execute([$id]);
  $payment = $q->fetch();

  if (!$payment) {
    http_response_code(404);
    echo json_encode(['success'=>false,'message'=>'Payment record not found.']);
    exit;
  }

  if ((int)$payment['user_id'] !== (int)$s['user_id']) {
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'You can only delete your own payment records.']);
    exit;
  }

  // Verified/paid payments are part of the official ledger and must not be silently deleted.
  if (in_array((string)$payment['status'], ['Verified','Paid'], true)) {
    http_response_code(409);
    echo json_encode(['success'=>false,'message'=>'Verified payments cannot be deleted because they are already part of the official payment ledger.']);
    exit;
  }

  $pdo->beginTransaction();
  $q = $pdo->prepare("DELETE FROM payments WHERE id=? AND user_id=? AND status NOT IN ('Verified','Paid')");
  $q->execute([$id,(int)$s['user_id']]);
  if ($q->rowCount() !== 1) {
    $pdo->rollBack();
    throw new RuntimeException('The payment could not be deleted.');
  }

  // Remove the uploaded proof after the database record is deleted.
  if (!empty($payment['proof_file_name'])) {
    $proof = __DIR__.'/uploads/payment_proofs/'.basename((string)$payment['proof_file_name']);
    if (is_file($proof)) @unlink($proof);
  }

  try {
    ensure_payment_modules_tables($pdo);
    $audit = $pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");
    $audit->execute([(int)$s['user_id'],'Deleted payment #'.$id.' ('.$payment['reference_number'].')']);
  } catch (Throwable $ignored) {
    // Deletion itself has already succeeded; audit logging must not undo it.
  }

  $pdo->commit();
  echo json_encode(['success'=>true,'message'=>'Payment record deleted successfully.','payment_id'=>$id]);
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('student_delete_payment.php: '.$e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Payment could not be deleted. '.$e->getMessage()]);
}
