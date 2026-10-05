<?php
require __DIR__.'/config.php';
$s=require_login(); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
header('Content-Type: application/json; charset=utf-8');
try{
  $in=json_decode(file_get_contents('php://input'),true)?:[];
  $id=(int)($in['payment_id']??0);
  if($id<1){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Payment ID is required.']);exit;}
  $q=$pdo->prepare("SELECT * FROM payments WHERE id=? AND user_id=? LIMIT 1");$q->execute([$id,(int)$s['user_id']]);$p=$q->fetch();
  if(!$p){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Payment not found.']);exit;}
  $mode=strtolower(trim((string)(getenv('HELLOMONEY_MODE')?:get_payment_setting($pdo,'hellomoney_mode','demo'))));
  if($mode!=='demo'){http_response_code(403);echo json_encode(['success'=>false,'message'=>'Demo confirmation is disabled in live mode.']);exit;}
  if($p['status']!=='Awaiting HelloMoney Payment'){echo json_encode(['success'=>true,'status'=>$p['status']]);exit;}
  $pdo->beginTransaction();
  $up=$pdo->prepare("UPDATE payments SET status='Pending Online Confirmation',provider_status='demo_paid',verified_at=NULL WHERE id=? AND user_id=? AND status='Awaiting HelloMoney Payment'");
  $up->execute([$id,(int)$s['user_id']]);
  $pdo->commit();
  echo json_encode(['success'=>true,'status'=>'Pending Online Confirmation','message'=>'Demo HelloMoney confirmation completed. The payment is now waiting for cashier verification.']);
}catch(Throwable $e){
  if($pdo->inTransaction())$pdo->rollBack();
  http_response_code(500);echo json_encode(['success'=>false,'message'=>'Unable to confirm the demo payment.']);
}
