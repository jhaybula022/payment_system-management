<?php
require __DIR__.'/config.php';
$s=require_login();
ensure_payment_modules_tables($pdo);
ensure_payments_table($pdo);

$userId=(int)$s['user_id'];
$summary=get_student_financial_summary($pdo,$userId);
$items=get_fee_items($pdo);

$items=get_student_fee_breakdown($pdo,$userId,$summary);

echo json_encode(['success'=>true,'items'=>$items,'summary'=>$summary,'as_of_date'=>(new DateTimeImmutable('today'))->format('Y-m-d')]);
