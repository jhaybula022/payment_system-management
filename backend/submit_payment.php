<?php
require __DIR__.'/config.php';
$s = require_login();

try {
  ensure_payments_table($pdo);
  ensure_payment_modules_tables($pdo);

  $amount = (float)str_replace(',', '', (string)($_POST['amount'] ?? 0));
  $feeItemId = (int)($_POST['fee_item_id'] ?? 0);
  $installmentId = (int)($_POST['installment_id'] ?? 0);
  $method = trim((string)($_POST['payment_method'] ?? ''));
  $channel = trim((string)($_POST['payment_channel'] ?? ''));
  $cashierId = (int)($_POST['cashier_id'] ?? 0);
  if(!in_array($channel,['Online','Face-to-Face'],true)) {
    $channel = (strcasecmp($method,'Cash')===0) ? 'Face-to-Face' : 'Online';
  }

  // Every student payment is routed to a selected cashier so the database
  // can identify exactly which cashier owns the payment workflow. This applies
  // to both Online and Face-to-Face submissions. HMA may still complete through
  // the provider, but the selected cashier remains linked to the record.
  if($cashierId<1) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Please choose the cashier who will receive/process this payment.']); exit; }
  $cq=$pdo->prepare("SELECT id,full_name,employee_id FROM users WHERE id=? AND role='cashier' AND status='Active' LIMIT 1");
  $cq->execute([$cashierId]);
  if(!$cq->fetch()) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Selected cashier account is not available.']); exit; }
  if ($channel==='Face-to-Face') {
    $method='Cash';
  } elseif (strcasecmp($method,'Cash')===0) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Cash payments must use the Face-to-Face payment option.']);
    exit;
  }

  if ($amount <= 0 || $method === '') {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Amount and payment method are required.']);
    exit;
  }

  // Face-to-face uses the student's student number as the transaction reference.
  // Online payments keep a unique portal reference.
  if ($channel==='Face-to-Face') {
    $uq=$pdo->prepare("SELECT student_id FROM users WHERE id=? AND role='user' LIMIT 1");
    $uq->execute([(int)$s['user_id']]);
    $studentNo=trim((string)$uq->fetchColumn());
    if($studentNo==='') { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Your student number is required for a face-to-face payment.']); exit; }
    $ref=$studentNo;
  } else {
    $ref = '';
    for ($attempt = 0; $attempt < 8; $attempt++) {
      $ref = 'BLCP-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));
      $checkRef = $pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference_number=? AND payment_channel='Online'");
      $checkRef->execute([$ref]);
      if (!(int)$checkRef->fetchColumn()) break;
      $ref = '';
    }
    if ($ref === '') throw new RuntimeException('Unable to generate a unique payment reference number. Please try again.');
  }

  $storedName = null;
  // Online payments require proof. Face-to-face payments are handed to the selected cashier,
  // so the cashier verification itself is the proof of collection.
  if ($channel==='Online') {
    if (!isset($_FILES['proof'])) { http_response_code(422); echo json_encode(['success'=>false,'message'=>'Proof of payment is required for online payment.']); exit; }
    $uploadError=(int)($_FILES['proof']['error']??UPLOAD_ERR_NO_FILE);
    if($uploadError!==UPLOAD_ERR_OK){
      $uploadMessages=[UPLOAD_ERR_INI_SIZE=>'The proof image is larger than the server upload limit. Please use an image under 5 MB.',UPLOAD_ERR_FORM_SIZE=>'The proof image is too large for the form upload limit.',UPLOAD_ERR_PARTIAL=>'The proof image upload was incomplete. Please try again.',UPLOAD_ERR_NO_FILE=>'Proof of payment is required for online payment.',UPLOAD_ERR_NO_TMP_DIR=>'PHP has no temporary upload folder. Check the XAMPP PHP configuration.',UPLOAD_ERR_CANT_WRITE=>'PHP could not write the uploaded file. Check the upload folder permissions.',UPLOAD_ERR_EXTENSION=>'A PHP extension stopped the proof image upload.'];
      http_response_code(422);echo json_encode(['success'=>false,'message'=>$uploadMessages[$uploadError]??'The proof image could not be uploaded.']);exit;
    }
    $file=$_FILES['proof'];
    if((int)$file['size']<=0 || (int)$file['size']>5*1024*1024){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Proof image must be larger than 0 bytes and no more than 5 MB.']);exit;}
    $mime='';if(class_exists('finfo')){$finfo=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$finfo->file($file['tmp_name']);}if($mime==='')$mime=(string)($file['type']??'');
    $allowed=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif','image/avif'=>'avif'];
    if(!isset($allowed[$mime])){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid proof image. Please upload JPG, PNG, WEBP, or GIF.']);exit;}
    if($mime!=='image/avif' && @getimagesize($file['tmp_name'])===false){http_response_code(422);echo json_encode(['success'=>false,'message'=>'The uploaded proof is not a valid image.']);exit;}
    $uploadDir=__DIR__.'/uploads/payment_proofs';if(!is_dir($uploadDir))@mkdir($uploadDir,0755,true);if(!is_writable($uploadDir))throw new RuntimeException('The payment proof upload folder is not writable.');
    $storedName=bin2hex(random_bytes(16)).'.'.$allowed[$mime];$destination=$uploadDir.'/'.$storedName;if(!move_uploaded_file($file['tmp_name'],$destination))throw new RuntimeException('Unable to save the uploaded proof image.');
  }

  if ($feeItemId <= 0) {
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Please select what you are paying for.']);
    exit;
  }

  $installments=get_payment_installments($pdo,(int)$s['user_id'],get_student_financial_summary($pdo,(int)$s['user_id']));
  $selectedInstallment=null;
  foreach($installments as $inst){ if((int)$inst['id']===$installmentId){$selectedInstallment=$inst;break;} }
  if(!$selectedInstallment){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Please select a valid payment period: Prelim, Midterm, or Finals.']);
    exit;
  }
  if($amount > (float)$selectedInstallment['available_to_pay']){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Payment amount exceeds the remaining '.$selectedInstallment['name'].' balance of ₱'.number_format((float)$selectedInstallment['available_to_pay'],2).'.']);
    exit;
  }

  // Validate the selected fee against the student's CURRENT remaining balance.
  $summary=get_student_financial_summary($pdo,(int)$s['user_id']);
  $items=get_fee_items($pdo);
  $selected=null;
  foreach($items as $item){
    if((int)$item['id']===$feeItemId){ $selected=$item; break; }
  }
  if(!$selected){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Selected fee is not available.']);
    exit;
  }

  $q=$pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN status='Verified' THEN amount ELSE 0 END),0) AS verified_paid,
      COALESCE(SUM(CASE WHEN status IN ('Pending Verification','Pending Online Confirmation','Awaiting HelloMoney Payment') THEN amount ELSE 0 END),0) AS pending_paid
      FROM payments WHERE user_id=? AND fee_item_id=?");
  $q->execute([(int)$s['user_id'],$feeItemId]);
  $feePayments=$q->fetch() ?: ['verified_paid'=>0,'pending_paid'=>0];
  $verifiedPaid=(float)$feePayments['verified_paid'];
  $pendingPaid=(float)$feePayments['pending_paid'];
  $discountShare=(float)$summary['discount_amount']*((float)$selected['percentage']/100);
  $adjusted=max(0,(float)$selected['amount']-$discountShare);
  $feeBalance=max(0,$adjusted-$verifiedPaid);
  if($feeBalance<=0){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'The selected fee has already been fully paid.']);
    exit;
  }
  $availableToPay=max(0,$feeBalance-$pendingPaid);
  if($availableToPay<=0){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'A payment for '.$selected['name'].' is already pending admin verification. Please wait for the verification result before submitting another payment for this fee.']);
    exit;
  }
  if($amount>$availableToPay){
    http_response_code(422);
    echo json_encode(['success'=>false,'message'=>'Payment amount cannot exceed the available amount for '.$selected['name'].' of ₱'.number_format($availableToPay,2).'. A pending payment of ₱'.number_format(min($pendingPaid,$feeBalance),2).' is already awaiting verification.']);
    exit;
  }

  try {
    $q = $pdo->prepare("INSERT INTO payments (user_id, fee_item_id, installment_id, amount, payment_method, payment_channel, reference_number, proof_file_name, status, cashier_id, payment_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURDATE())");
    $status = $channel==='Face-to-Face' ? 'Pending Verification' : 'Pending Online Confirmation';
    $q->execute([(int)$s['user_id'], $feeItemId, $installmentId, $amount, $method, $channel, $ref, $storedName, $status, $cashierId]);
  } catch (Throwable $dbError) {
    if(isset($destination)) @unlink($destination);
    throw $dbError;
  }

  echo json_encode([
    'success'=>true,
    'message'=>$channel==='Face-to-Face' ? 'Payment submitted to the selected cashier and is now pending cashier verification.' : 'Online payment submitted and is pending online confirmation.',
    'payment_id'=>(int)$pdo->lastInsertId(),
    'status'=>$status,
    'payment_channel'=>$channel,
    'cashier_id'=>$cashierId,
    'reference_number'=>$ref,
    'installment_id'=>$installmentId,
    'installment_name'=>$selectedInstallment['name'],
    'proof_file_name'=>$storedName
  ]);
} catch (Throwable $e) {
  http_response_code(500);
  error_log('submit_payment.php: '.$e->getMessage());
  $message = $e instanceof PDOException
    ? 'Payment could not be saved because the database rejected the payment. Please make sure the payments table contains fee_item_id and proof_file_name.'
    : $e->getMessage();
  echo json_encode(['success'=>false,'message'=>$message]);
}
