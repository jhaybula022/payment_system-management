<?php
require __DIR__.'/config.php';
$s=require_login(); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
header('Content-Type: application/json; charset=utf-8');

function hm_get(string $url,string $key): array {
  if(!function_exists('curl_init')) throw new RuntimeException('PHP cURL extension is required.');
  $ch=curl_init($url); curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,CURLOPT_HTTPHEADER=>['Accept: application/json','Authorization: Basic '.base64_encode($key.':')]]); 
  $raw=curl_exec($ch); $err=curl_error($ch); $code=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
  if($err||$code<200||$code>=300) throw new RuntimeException('Unable to check payment status.');
  $d=json_decode((string)$raw,true); if(!is_array($d)) throw new RuntimeException('Invalid payment status response.'); return $d;
}
try{
  $id=(int)($_GET['payment_id']??0);
  if($id<1){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Payment ID is required.']);exit;}
  $q=$pdo->prepare("SELECT * FROM payments WHERE id=? AND user_id=? LIMIT 1");$q->execute([$id,(int)$s['user_id']]);$p=$q->fetch();
  if(!$p){http_response_code(404);echo json_encode(['success'=>false,'message'=>'Payment not found.']);exit;}

  if($p['status']==='Paid'){
    $receipt=generate_payment_receipt($pdo,$id);
    echo json_encode(['success'=>true,'status'=>'Paid','receipt_number'=>$receipt,'receipt_file_name'=>$p['receipt_file_name']??null]);exit;
  }

  $mode=strtolower(trim((string)(getenv('HELLOMONEY_MODE')?:get_payment_setting($pdo,'hellomoney_mode','demo'))));
  $secret=(string)(getenv($mode==='test'?'PAYMONGO_TEST_SECRET_KEY':'PAYMONGO_LIVE_SECRET_KEY') ?: '');
  if($secret==='' && $mode==='live') $secret=(string)(getenv('PAYMONGO_SECRET_KEY')?:'');
  if(!empty($p['provider_payment_id']) && $secret!==''){
    $d=hm_get('https://api.paymongo.com/v1/payment_intents/'.rawurlencode((string)$p['provider_payment_id']),$secret);
    $attrs=$d['data']['attributes']??[];
    $providerStatus=(string)($attrs['status']??'');
    $newStatus=$p['status'];
    if(in_array($providerStatus,['succeeded','paid'],true))$newStatus='Pending Online Confirmation';
    elseif(in_array($providerStatus,['failed','cancelled','canceled'],true))$newStatus='Rejected';
    $up=$pdo->prepare("UPDATE payments SET provider_status=?,status=?,verified_at=CASE WHEN ?='Paid' THEN NOW() ELSE verified_at END WHERE id=? AND user_id=?");
    $up->execute([$providerStatus,$newStatus,$newStatus,$id,(int)$s['user_id']]);
    if($newStatus==='Pending Online Confirmation'){
      echo json_encode(['success'=>true,'status'=>'Pending Online Confirmation','provider_status'=>$providerStatus,'receipt_number'=>$receipt,'receipt_file_name'=>$p['receipt_file_name']??null,'message'=>'HelloMoney payment confirmed by the provider and is now waiting for cashier verification.']);exit;
    }
    echo json_encode(['success'=>true,'status'=>$newStatus,'provider_status'=>$providerStatus,'message'=>'Payment is still being processed.']);exit;
  }

  echo json_encode(['success'=>true,'status'=>$p['status'],'mode'=>'demo','message'=>'Demo payment is waiting for confirmation.']);
}catch(Throwable $e){
  http_response_code(500); error_log('hellomoney_status.php: '.$e->getMessage());
  echo json_encode(['success'=>false,'message'=>'Unable to check HelloMoney payment status.']);
}
