<?php
require __DIR__.'/config.php';
require_login();
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS ai_knowledge (id INT AUTO_INCREMENT PRIMARY KEY, question VARCHAR(180) NOT NULL, answer TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $q=$pdo->query("SELECT id,question,answer FROM ai_knowledge WHERE status='Active' ORDER BY id DESC");
  echo json_encode(['success'=>true,'items'=>$q->fetchAll()]);
} catch(Throwable $e){ http_response_code(500); echo json_encode(['success'=>false,'message'=>'AI knowledge is unavailable.']); }
