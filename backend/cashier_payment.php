<?php
require __DIR__.'/config.php';
$s=require_cashier(); ensure_user_academic_fields($pdo); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
$in=json_decode(file_get_contents('php://input'),true) ?: $_POST;
$studentId=(int)($in['student_id']??0); $feeId=(int)($in['fee_item_id']??0); $amount=(float)str_replace(',', '', (string)($in['amount']??0)); $ref=trim((string)($in['reference_number']??''));
if($studentId<1 || $amount<=0){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Student and a valid payment amount are required.']);exit;}

try{
  $q=$pdo->prepare("SELECT id,full_name,student_id FROM users WHERE id=? AND role='user' AND status='Active' LIMIT 1");$q->execute([$studentId]);$student=$q->fetch();
  if(!$student){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Active student account not found.']);exit;}
  $ref=trim((string)$student['student_id']);
  if($ref==='')throw new InvalidArgumentException('Student number is required for a face-to-face payment.');
  if($feeId>0){$fq=$pdo->prepare("SELECT id FROM fee_items WHERE id=? AND active=1 LIMIT 1");$fq->execute([$feeId]);if(!$fq->fetch())$feeId=null;}
  if($feeId>0){
    $sum=get_student_financial_summary($pdo,$studentId);$items=get_student_fee_breakdown($pdo,$studentId,$sum);$selected=null;foreach($items as $it){if((int)$it['id']===$feeId){$selected=$it;break;}}
    if(!$selected)throw new InvalidArgumentException('Selected fee is not available.');
    $available=(float)($selected['available_to_pay']??$selected['balance']??0);
    if($amount>$available)throw new InvalidArgumentException('Payment amount exceeds the selected fee balance of ₱'.number_format($available,2).'.');
  }
  
  $q=$pdo->prepare("INSERT INTO payments(user_id,fee_item_id,amount,payment_method,payment_channel,reference_number,status,cashier_id,processed_at,verified_by,verified_at,payment_date) VALUES(?,?,?,?,?,?,?,?,NOW(),NULL,NULL,CURDATE())");
  $q->execute([$studentId,$feeId,$amount,'Cash','Face-to-Face',$ref,'Pending Verification',(int)$s['user_id']]);
  $id=(int)$pdo->lastInsertId();
  $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");$aq->execute([(int)$s['user_id'],'Cashier recorded face-to-face payment #'.$id.' for student #'.$studentId]);
  echo json_encode(['success'=>true,'message'=>'Face-to-face payment recorded and queued for cashier verification.','payment_id'=>$id,'reference_number'=>$ref]);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}catch(Throwable $e){http_response_code(500);error_log('cashier_payment.php: '.$e->getMessage());echo json_encode(['success'=>false,'message'=>'Payment could not be recorded.']);}
