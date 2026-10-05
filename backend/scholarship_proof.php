<?php
require __DIR__.'/config.php';
$s=require_login();
ensure_payment_modules_tables($pdo);
$id=(int)($_GET['id']??0); if($id<1){http_response_code(400);exit('Invalid request.');}
$q=$pdo->prepare('SELECT user_id,proof_file_name FROM scholarship_applications WHERE id=? LIMIT 1');$q->execute([$id]);$r=$q->fetch();
if(!$r || empty($r['proof_file_name'])){http_response_code(404);exit('Proof not found.');}
if(($s['role']??'')!=='admin' && (int)$r['user_id']!==(int)$s['user_id']){http_response_code(403);exit('Access denied.');}
$path=__DIR__.'/uploads/scholarship_proofs/'.basename($r['proof_file_name']); if(!is_file($path)){http_response_code(404);exit('Proof file not found.');}
$mime=(new finfo(FILEINFO_MIME_TYPE))->file($path) ?: 'application/octet-stream'; header('Content-Type: '.$mime); header('Content-Disposition:inline; filename="scholarship-proof"'); header('X-Content-Type-Options:nosniff'); readfile($path);
