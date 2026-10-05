<?php
require __DIR__.'/config.php';
$s=require_cashier();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
$in=json_decode(file_get_contents('php://input'),true) ?: [];
$id=(int)($in['id']??0); $status=(string)($in['status']??'');
if($id<1 || !in_array($status,['Verified','Rejected'],true)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid payment verification action.']);exit;}
try{
  $q=$pdo->prepare("SELECT id,status,payment_channel,cashier_id FROM payments WHERE id=? AND cashier_id=? AND payment_channel IN ('Face-to-Face','Online') LIMIT 1");
  $q->execute([$id,(int)$s['user_id']]); $p=$q->fetch();
  if(!$p){http_response_code(404);echo json_encode(['success'=>false,'message'=>'This payment is not assigned to your cashier account.']);exit;}
  if(!in_array((string)$p['status'],['Pending Verification','Pending Online Confirmation','Awaiting HelloMoney Payment'],true)){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Only pending payments can be verified or rejected.']);exit;}
  if($p['status']==='Verified' && $status==='Rejected'){http_response_code(409);echo json_encode(['success'=>false,'message'=>'A verified payment cannot be rejected.']);exit;}
  $q=$pdo->prepare("UPDATE payments SET status=?,verified_by=?,verified_at=CASE WHEN ?='Verified' THEN NOW() ELSE NULL END,processed_at=COALESCE(processed_at,NOW()),payment_date=COALESCE(payment_date,CURDATE()) WHERE id=? AND cashier_id=?");
  $q->execute([$status,(int)$s['user_id'],$status,$id,(int)$s['user_id']]);
  $receiptNumber=null;
  if($status==='Verified'){
    $receiptNumber=generate_payment_receipt($pdo,$id);
  }
  $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");$aq->execute([(int)$s['user_id'],'Cashier verification: payment #'.$id.' marked '.$status]);
  echo json_encode(['success'=>true,'message'=>'Payment marked '.$status.' by your cashier account.','receipt_number'=>$receiptNumber]);
}catch(Throwable $e){error_log('cashier_update_payment.php: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Unable to update the payment.']);}
