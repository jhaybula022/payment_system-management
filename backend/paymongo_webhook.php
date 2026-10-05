<?php
declare(strict_types=1);

// PayMongo webhook endpoint. This file intentionally does NOT require a logged-in
// student/admin session because PayMongo calls it server-to-server.
require __DIR__.'/config.php';
ensure_payments_table($pdo);
ensure_payment_modules_tables($pdo);
ensure_payment_webhook_table($pdo);

header('Content-Type: application/json; charset=utf-8');

function webhook_fail(string $message, int $code=400): never {
  http_response_code($code);
  echo json_encode(['success'=>false,'message'=>$message]);
  exit;
}

$raw=file_get_contents('php://input') ?: '';
$signature=(string)($_SERVER['HTTP_PAYMONGO_SIGNATURE'] ?? '');
$secret=(string)(getenv('PAYMONGO_WEBHOOK_SECRET') ?: '');

if($secret==='') webhook_fail('Webhook secret is not configured.',500);
if($signature==='') webhook_fail('Missing Paymongo-Signature header.',401);

$parts=[];
foreach(explode(',', $signature) as $part){
  $kv=explode('=',trim($part),2);
  if(count($kv)===2)$parts[$kv[0]]=$kv[1];
}
$timestamp=(string)($parts['t'] ?? '');
$testSig=(string)($parts['te'] ?? '');
$liveSig=(string)($parts['li'] ?? '');
if($timestamp==='' || ($testSig==='' && $liveSig==='')) webhook_fail('Invalid webhook signature.',401);

// Reject very old requests to reduce replay risk. Five minutes is deliberately
// generous enough for normal delivery while still protecting the endpoint.
if(!ctype_digit($timestamp) || abs(time()-(int)$timestamp)>300) webhook_fail('Expired webhook signature.',401);

$expected=hash_hmac('sha256',$timestamp.'.'.$raw,$secret);
$provided=$liveSig!=='' ? $liveSig : $testSig;
if(!hash_equals($expected,$provided)) webhook_fail('Invalid webhook signature.',401);

$payload=json_decode($raw,true);
if(!is_array($payload)) webhook_fail('Invalid JSON payload.',400);

$eventId=(string)($payload['data']['id'] ?? '');
$eventType=(string)($payload['data']['attributes']['type'] ?? '');
$livemode=!empty($payload['data']['attributes']['livemode']);
if($eventId==='') webhook_fail('Missing event ID.',400);

// Idempotency: PayMongo may retry an event. Process each event ID only once.
$ins=$pdo->prepare("INSERT IGNORE INTO payment_webhook_events(event_id,event_type,livemode) VALUES(?,?,?)");
$ins->execute([$eventId,$eventType,$livemode?1:0]);
if($ins->rowCount()===0){
  echo json_encode(['success'=>true,'duplicate'=>true]);
  exit;
}

try {
  $resource=$payload['data']['attributes']['data'] ?? [];
  $attrs=is_array($resource['attributes'] ?? null) ? $resource['attributes'] : [];
  $paymentIntentId=(string)($attrs['payment_intent_id'] ?? '');
  $resourceId=(string)($resource['id'] ?? '');

  if($eventType==='payment.paid' || $eventType==='payment_intent.succeeded') {
    // For Payment Acceptance, payment.paid carries payment_intent_id. The
    // payment-intent event uses its own resource ID.
    if($paymentIntentId==='') $paymentIntentId=$resourceId;
    if($paymentIntentId!=='') {
      $q=$pdo->prepare("SELECT id,user_id FROM payments WHERE provider_payment_id=? LIMIT 1");
      $q->execute([$paymentIntentId]);
      $p=$q->fetch();
      if($p){
        $up=$pdo->prepare("UPDATE payments SET status='Pending Online Confirmation', provider_status=?, verified_at=NULL WHERE id=? AND status NOT IN ('Verified','Rejected')");
        $up->execute([$eventType,(int)$p['id']]);
        // Provider confirmation is not final verification. The assigned cashier must verify it.
      }
    }
  } elseif($eventType==='payment.failed') {
    if($paymentIntentId!=='') {
      $up=$pdo->prepare("UPDATE payments SET provider_status=?, status='Rejected' WHERE provider_payment_id=? AND status='Awaiting HelloMoney Payment'");
      $up->execute([$eventType,$paymentIntentId]);
    }
  } elseif($eventType==='qr.paid') {
    $meta=$attrs['metadata'] ?? [];
    $portalRef=(string)($meta['portal_reference'] ?? $meta['reference_number'] ?? '');
    if($portalRef!=='') {
      $up=$pdo->prepare("UPDATE payments SET status='Pending Online Confirmation', provider_status=?, verified_at=NULL WHERE reference_number=? AND status NOT IN ('Verified','Rejected')");
      $up->execute([$eventType,$portalRef]);
      $q=$pdo->prepare("SELECT id FROM payments WHERE reference_number=? LIMIT 1");$q->execute([$portalRef]);
      // Receipt is generated only after cashier verification.
    }
  } elseif($eventType==='qrph.expired' || $eventType==='qr.expired') {
    // Expiry events can reference the QR object rather than the Payment Intent.
    // The status endpoint will also reconcile the intent if needed. Mark only
    // matching awaiting transactions when the provider includes our metadata.
    $meta=$attrs['metadata'] ?? [];
    $portalRef=(string)($meta['portal_reference'] ?? $meta['reference_number'] ?? '');
    if($portalRef!=='') {
      $up=$pdo->prepare("UPDATE payments SET provider_status=?, status='Rejected' WHERE reference_number=? AND status='Awaiting HelloMoney Payment'");
      $up->execute([$eventType,$portalRef]);
    }
  }

  $pdo->prepare("UPDATE payment_webhook_events SET processed_at=NOW() WHERE event_id=?")->execute([$eventId]);
  echo json_encode(['success'=>true]);
} catch(Throwable $e) {
  error_log('paymongo_webhook.php: '.$e->getMessage());
  // Return 500 so PayMongo retries a transient processing failure.
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Webhook processing failed.']);
}
