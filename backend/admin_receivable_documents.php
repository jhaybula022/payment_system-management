<?php
require __DIR__.'/config.php';
$admin=require_admin();
ensure_receivable_documents_table($pdo);
$base=__DIR__.'/uploads/receivables'; if(!is_dir($base)) @mkdir($base,0755,true);
if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['id'])){
  $id=(int)$_GET['id']; $q=$pdo->prepare('SELECT * FROM receivable_documents WHERE id=? LIMIT 1'); $q->execute([$id]); $d=$q->fetch();
  if(!$d){http_response_code(404);echo 'Document not found';exit;}
  $path=$base.'/'.$d['stored_name']; if(!is_file($path)){http_response_code(404);echo 'PDF file not found';exit;}
  header('Content-Type: application/pdf'); header('Content-Disposition: inline; filename="'.basename($d['original_name']).'"'); header('Content-Length: '.filesize($path)); readfile($path); exit;
}
if($_SERVER['REQUEST_METHOD']==='GET'){
  $rows=$pdo->query('SELECT id,original_name,file_size,created_at FROM receivable_documents ORDER BY created_at DESC LIMIT 100')->fetchAll();
  echo json_encode(['success'=>true,'documents'=>$rows]); exit;
}
if($_SERVER['REQUEST_METHOD']==='POST'){
  if(!isset($_FILES['document'])){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Please select a PDF file.']);exit;}
  $f=$_FILES['document']; if((int)$f['error']!==UPLOAD_ERR_OK){http_response_code(422);echo json_encode(['success'=>false,'message'=>'PDF upload failed.']);exit;}
  if((int)$f['size']>10*1024*1024){http_response_code(422);echo json_encode(['success'=>false,'message'=>'PDF must be 10 MB or smaller.']);exit;}
  $mime=(new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']); if($mime!=='application/pdf'){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Only valid PDF files are allowed.']);exit;}
  $safe=preg_replace('/[^A-Za-z0-9._-]/','_',basename((string)$f['name'])); $stored=date('YmdHis').'_'.bin2hex(random_bytes(5)).'.pdf';
  if(!move_uploaded_file($f['tmp_name'],$base.'/'.$stored)){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Unable to save the PDF file.']);exit;}
  $q=$pdo->prepare('INSERT INTO receivable_documents(original_name,stored_name,mime_type,file_size,uploaded_by) VALUES(?,?,?,?,?)'); $q->execute([$safe,$stored,'application/pdf',(int)$f['size'],(int)$admin['user_id']]);
  $aq=$pdo->prepare('INSERT INTO audit_logs(user_id,action) VALUES(?,?)'); $aq->execute([(int)$admin['user_id'],'Uploaded Accounts Receivable PDF: '.$safe]);
  echo json_encode(['success'=>true,'message'=>'Receivable PDF uploaded successfully.','id'=>(int)$pdo->lastInsertId()]); exit;
}
http_response_code(405); echo json_encode(['success'=>false,'message'=>'Method not allowed.']);
