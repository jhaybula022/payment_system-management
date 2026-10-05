<?php
require __DIR__.'/config.php'; require_admin();
try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS ai_knowledge (id INT AUTO_INCREMENT PRIMARY KEY, question VARCHAR(180) NOT NULL, answer TEXT NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Active', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  if($_SERVER['REQUEST_METHOD']==='GET'){ $q=$pdo->query("SELECT id,question,answer,status,created_at,updated_at FROM ai_knowledge ORDER BY id DESC"); echo json_encode(['success'=>true,'items'=>$q->fetchAll()]); exit; }
  $in=json_decode(file_get_contents('php://input'),true) ?: []; $action=$in['action']??''; $id=(int)($in['id']??0); $question=trim((string)($in['question']??'')); $answer=trim((string)($in['answer']??''));
  if($action==='create'){ if($question===''||$answer==='') throw new RuntimeException('Question and answer are required.'); $q=$pdo->prepare("INSERT INTO ai_knowledge(question,answer,status) VALUES(?,?,'Active')"); $q->execute([$question,$answer]); $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Added AI knowledge item #'.$pdo->lastInsertId()]); echo json_encode(['success'=>true]); exit; }
  if($action==='update'){ if($id<1||$question===''||$answer==='') throw new RuntimeException('Invalid knowledge item.'); $q=$pdo->prepare("UPDATE ai_knowledge SET question=?,answer=? WHERE id=?"); $q->execute([$question,$answer,$id]); echo json_encode(['success'=>true]); exit; }
  if($action==='delete'){ if($id<1) throw new RuntimeException('Invalid knowledge item.'); $q=$pdo->prepare("DELETE FROM ai_knowledge WHERE id=?"); $q->execute([$id]); $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Deleted AI knowledge item #'.$id]); echo json_encode(['success'=>true]); exit; }
  throw new RuntimeException('Unknown action.');
} catch(Throwable $e){ http_response_code(422); echo json_encode(['success'=>false,'message'=>$e->getMessage() ?: 'Knowledge base operation failed.']); }
