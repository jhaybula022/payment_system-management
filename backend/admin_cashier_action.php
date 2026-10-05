<?php
require __DIR__.'/config.php'; require_admin(); ensure_user_academic_fields($pdo); ensure_payment_modules_tables($pdo);
$in=json_decode(file_get_contents('php://input'),true) ?: []; $action=(string)($in['action']??'');
try{
 if($action==='create'){
  $username=trim((string)($in['username']??''));$full=trim((string)($in['full_name']??''));$emp=trim((string)($in['employee_id']??''));$pw=(string)($in['password']??'');$status=(string)($in['status']??'Active');
  if($username===''||$full===''||$emp===''||strlen($pw)<8){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Username, full name, employee ID, and password (8+ characters) are required.']);exit;}
  if(!in_array($status,['Active','Inactive'],true))$status='Active';
  $q=$pdo->prepare('SELECT id FROM users WHERE username=? OR employee_id=? LIMIT 1');$q->execute([$username,$emp]);if($q->fetch()){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Username or employee ID is already registered.']);exit;}
  $q=$pdo->prepare("INSERT INTO users(username,password_hash,full_name,student_id,year_level,employee_id,role,status) VALUES(?,?,?,?,?,?, 'cashier', ?)");$q->execute([$username,password_hash($pw,PASSWORD_DEFAULT),$full,'','',$emp,$status]);
  $id=(int)$pdo->lastInsertId();$aq=$pdo->prepare('INSERT INTO audit_logs(user_id,action) VALUES(?,?)');$aq->execute([(int)$_SESSION['user_id'],'Created cashier account #'.$id]);echo json_encode(['success'=>true,'message'=>'Cashier account created.']);exit;
 }
 $id=(int)($in['id']??0); if($id<1)throw new InvalidArgumentException('Invalid cashier ID.');
 if($action==='update'){
  $username=trim((string)($in['username']??''));$full=trim((string)($in['full_name']??''));$emp=trim((string)($in['employee_id']??''));$status=(string)($in['status']??'Active');
  if($username===''||$full===''||$emp===''||!in_array($status,['Active','Inactive'],true))throw new InvalidArgumentException('Invalid cashier information.');
  $q=$pdo->prepare('SELECT id FROM users WHERE (username=? OR employee_id=?) AND id<>? LIMIT 1');$q->execute([$username,$emp,$id]);if($q->fetch()){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Username or employee ID is already used.']);exit;}
  $q=$pdo->prepare("UPDATE users SET username=?,full_name=?,employee_id=?,status=? WHERE id=? AND role='cashier'");$q->execute([$username,$full,$emp,$status,$id]);
  if(trim((string)($in['password']??''))!==''){ $pw=(string)$in['password'];if(strlen($pw)<8)throw new InvalidArgumentException('Password must be at least 8 characters.');$q=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='cashier'");$q->execute([password_hash($pw,PASSWORD_DEFAULT),$id]); }
  $aq=$pdo->prepare('INSERT INTO audit_logs(user_id,action) VALUES(?,?)');$aq->execute([(int)$_SESSION['user_id'],'Updated cashier account #'.$id]);echo json_encode(['success'=>true,'message'=>'Cashier account updated.']);exit;
 }
 if(in_array($action,['activate','deactivate'],true)){$st=$action==='activate'?'Active':'Inactive';$q=$pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='cashier'");$q->execute([$st,$id]);echo json_encode(['success'=>true,'message'=>'Cashier status updated.']);exit;}
 throw new InvalidArgumentException('Unsupported action.');
}catch(Throwable $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
