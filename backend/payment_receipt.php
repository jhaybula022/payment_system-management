<?php
require __DIR__.'/config.php';
$s=require_login(); ensure_payments_table($pdo);
$id=(int)($_GET['id']??0);
$q=$pdo->prepare("SELECT receipt_file_name,user_id,receipt_number,status,cashier_id FROM payments WHERE id=? LIMIT 1");$q->execute([$id]);$p=$q->fetch();
if(!$p){http_response_code(404);echo 'Receipt not found.';exit;}
$allowed=((int)$p['user_id']===(int)$s['user_id'] || ($s['role']??'')==='admin');
if(($s['role']??'')==='cashier' && (int)($p['cashier_id']??0)===(int)$s['user_id']) $allowed=true;
if(!$allowed){http_response_code(404);echo 'Receipt not found.';exit;}
if(!in_array((string)$p['status'],['Paid','Verified'],true)){http_response_code(409);echo 'Receipt is available after payment confirmation.';exit;}
$receipt=generate_payment_receipt($pdo,$id);
$q=$pdo->prepare("SELECT receipt_file_name FROM payments WHERE id=?");$q->execute([$id]);$file=$q->fetchColumn();
$path=__DIR__.'/uploads/payment_receipts/'.basename((string)$file);
if(is_file($path)){header('Content-Type:text/html; charset=utf-8');readfile($path);exit;}
http_response_code(404);echo 'Receipt file not found.';
