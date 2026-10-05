<?php
require __DIR__.'/config.php';
$s=require_login();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);

header('Content-Type: application/json; charset=utf-8');

function hm_json_request(string $url, string $method, array $payload, string $apiKey): array {
  $idempotencyKey=(string)($payload['_idempotency_key'] ?? '');
  unset($payload['_idempotency_key']);
  if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required for live QR payments.');
  $ch=curl_init($url);
  curl_setopt_array($ch,[
    CURLOPT_RETURNTRANSFER=>true,
    CURLOPT_TIMEOUT=>30,
    CURLOPT_CUSTOMREQUEST=>$method,
    CURLOPT_POSTFIELDS=>json_encode($payload),
    CURLOPT_HTTPHEADER=>[
      'Accept: application/json','Content-Type: application/json',
      'Authorization: Basic '.base64_encode($apiKey.':'),
      'Idempotency-Key: '.($idempotencyKey!==''?$idempotencyKey:bin2hex(random_bytes(16)))
    ]
  ]);
  $raw=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($err) throw new RuntimeException('Payment provider connection failed.');
  $data=json_decode((string)$raw,true);
  if($code<200||$code>=300||!is_array($data)) throw new RuntimeException('The QR payment provider could not create the payment.');
  return $data;
}

try{
  $amount=(float)($_POST['amount']??0);
  $feeId=(int)($_POST['fee_item_id']??0);
  $installmentId=(int)($_POST['installment_id']??0);
  $cashierId=(int)($_POST['cashier_id']??0);
  if($cashierId<1){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Please choose the cashier who will receive/process this online payment.']);exit;}
  $cq=$pdo->prepare("SELECT id FROM users WHERE id=? AND role='cashier' AND status='Active' LIMIT 1");
  $cq->execute([$cashierId]);
  if(!$cq->fetch()){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Selected cashier account is not available.']);exit;}
  if($amount<=0||$feeId<=0){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Select a fee and enter a valid amount.']);exit;}

  $summary=get_student_financial_summary($pdo,(int)$s['user_id']);
  $installments=get_payment_installments($pdo,(int)$s['user_id'],$summary);
  $selectedInstallment=null;
  foreach($installments as $inst) if((int)$inst['id']===$installmentId){$selectedInstallment=$inst;break;}
  if(!$selectedInstallment){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Please select Prelim, Midterm, or Finals before creating the QR payment.']);exit;}
  if($amount>(float)$selectedInstallment['available_to_pay']){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Payment amount exceeds the remaining '.$selectedInstallment['name'].' balance of ₱'.number_format((float)$selectedInstallment['available_to_pay'],2).'.']);exit;}
  $items=get_fee_items($pdo); $selected=null;
  foreach($items as $item) if((int)$item['id']===$feeId){$selected=$item;break;}
  if(!$selected){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Selected fee is not available.']);exit;}

  $q=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN status IN ('Verified','Paid') THEN amount ELSE 0 END),0) verified_paid,
                           COALESCE(SUM(CASE WHEN status IN ('Pending Verification','Awaiting HelloMoney Payment') THEN amount ELSE 0 END),0) pending_paid
                    FROM payments WHERE user_id=? AND fee_item_id=?");
  $q->execute([(int)$s['user_id'],$feeId]); $fp=$q->fetch()?:['verified_paid'=>0,'pending_paid'=>0];
  $discountShare=(float)$summary['discount_amount']*((float)$selected['percentage']/100);
  $adjusted=max(0,(float)$selected['amount']-$discountShare);
  $balance=max(0,$adjusted-(float)$fp['verified_paid']);
  $available=max(0,$balance-(float)$fp['pending_paid']);
  if($available<=0){http_response_code(422);echo json_encode(['success'=>false,'message'=>'This fee has no amount currently available for payment.']);exit;}
  if($amount>$available){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Payment amount cannot exceed '.number_format($available,2).'.']);exit;}

  $ref='';
  for($i=0;$i<8;$i++){
    $ref='BLCP-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
    $c=$pdo->prepare("SELECT COUNT(*) FROM payments WHERE reference_number=?");$c->execute([$ref]);
    if(!(int)$c->fetchColumn())break;$ref='';
  }
  if($ref==='')throw new RuntimeException('Unable to generate a unique payment reference.');

  $expires=(new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
  $ins=$pdo->prepare("INSERT INTO payments(user_id,fee_item_id,installment_id,amount,payment_method,payment_channel,reference_number,status,cashier_id,payment_expires_at) VALUES(?,?,?,?,?,'Online',?,'Awaiting HelloMoney Payment',?,?)");
  $ins->execute([(int)$s['user_id'],$feeId,$installmentId,$amount,'Hello Money App (HMA)',$ref,$cashierId,$expires]);
  $paymentId=(int)$pdo->lastInsertId();

  $mode=strtolower(trim((string)(getenv('HELLOMONEY_MODE')?:get_payment_setting($pdo,'hellomoney_mode','demo'))));
  $secret=(string)(getenv($mode==='test'?'PAYMONGO_TEST_SECRET_KEY':'PAYMONGO_LIVE_SECRET_KEY') ?: '');
  $public=(string)(getenv($mode==='test'?'PAYMONGO_TEST_PUBLIC_KEY':'PAYMONGO_LIVE_PUBLIC_KEY') ?: '');
  // Backward-compatible fallback for the earlier single-key setup.
  if($secret==='' && $mode==='live') $secret=(string)(getenv('PAYMONGO_SECRET_KEY')?:'');
  if($public==='' && $mode==='live') $public=(string)(getenv('PAYMONGO_PUBLIC_KEY')?:'');

  // Real QRPh route: HelloMoney can be used as the payer app because QR Ph is
  // interoperable. The school receives funds through its configured merchant
  // payment provider (here PayMongo). Test mode uses PayMongo test credentials;
  // live mode uses live credentials.
  if(in_array($mode,['test','live'],true) && $secret!=='' && $public!==''){
    $intent=hm_json_request('https://api.paymongo.com/v1/payment_intents','POST',[
      'data'=>['attributes'=>[
        'amount'=>(int)round($amount*100),
        'currency'=>'PHP',
        'payment_method_allowed'=>['qrph'],
        'description'=>$selected['name'].' - '.$ref,
        'metadata'=>['portal_reference'=>$ref,'portal_payment_id'=>(string)$paymentId,'installment_id'=>(string)$installmentId]
      ]],
      '_idempotency_key'=>$ref
    ],$secret);
    $intentId=(string)($intent['data']['id']??'');
    $clientKey=(string)($intent['data']['attributes']['client_key']??'');
    if($intentId===''||$clientKey==='')throw new RuntimeException('The live QR provider did not return a payment intent.');

    $pm=hm_json_request('https://api.paymongo.com/v1/payment_methods','POST',[
      'data'=>['attributes'=>['type'=>'qrph']]
    ],$public);
    $pmId=(string)($pm['data']['id']??'');
    if($pmId==='')throw new RuntimeException('The live QR provider did not return a QR payment method.');

    $attach=hm_json_request('https://api.paymongo.com/v1/payment_intents/'.$intentId.'/attach','POST',[
      'data'=>['attributes'=>['payment_method'=>$pmId,'client_key'=>$clientKey]]
    ],$public);
    $attrs=$attach['data']['attributes']??[];
    $qr=(string)($attrs['next_action']['code']['image_url']??'');
    if($qr==='')throw new RuntimeException('The live QR provider did not return a QR image.');
    $up=$pdo->prepare("UPDATE payments SET provider_payment_id=?,provider_status=?,payment_expires_at=? WHERE id=?");
    $up->execute([$intentId,(string)($attrs['status']??'awaiting_next_action'),$expires,$paymentId]);
    echo json_encode(['success'=>true,'mode'=>$mode,'payment_id'=>$paymentId,'reference_number'=>$ref,'installment_id'=>$installmentId,'installment_name'=>$selectedInstallment['name'],'status'=>'Awaiting HelloMoney Payment','qr_image_url'=>$qr,'expires_at'=>$expires,'message'=>'Scan this QR Ph code with HelloMoney, review the amount and confirm the payment.']);
    exit;
  }

  // Safe capstone/demo mode. This QR is NOT a real money-collection QR;
  // it encodes the portal transaction reference so the complete UI flow
  // can be demonstrated without live payment credentials.
  $demoPayload='BESTLINK-DEMO|HELLOMONEY|'.$ref.'|PHP'.number_format($amount,2,'.','');
  $qr='https://quickchart.io/qr?size=320&margin=2&text='.rawurlencode($demoPayload);
  $up=$pdo->prepare("UPDATE payments SET provider_status='demo_qr' WHERE id=?");$up->execute([$paymentId]);
  echo json_encode([
    'success'=>true,'mode'=>'demo','payment_id'=>$paymentId,'reference_number'=>$ref,'installment_id'=>$installmentId,'installment_name'=>$selectedInstallment['name'],
    'status'=>'Awaiting HelloMoney Payment','qr_image_url'=>$qr,'expires_at'=>$expires,
    'message'=>'Demo QR displayed. For real HelloMoney/QR Ph collection, configure the live QR provider credentials.'
  ]);
}catch(Throwable $e){
  if(isset($paymentId) && $paymentId){
    try{$pdo->prepare("DELETE FROM payments WHERE id=? AND status='Awaiting HelloMoney Payment'")->execute([$paymentId]);}catch(Throwable $ignored){}
  }
  http_response_code(500); error_log('create_hellomoney_payment.php: '.$e->getMessage());
  echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
}
