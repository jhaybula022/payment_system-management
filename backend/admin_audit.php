<?php
require __DIR__.'/config.php'; require_admin();
try {
  $q=$pdo->query("SELECT a.id,a.action,a.created_at,u.username,u.full_name AS admin_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 100");
  echo json_encode(['success'=>true,'logs'=>$q->fetchAll()]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['success'=>false,'message'=>'Unable to load audit activity.']); }
