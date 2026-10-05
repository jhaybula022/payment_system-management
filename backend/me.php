<?php
require __DIR__.'/config.php';
if(empty($_SESSION['user_id'])){echo json_encode(['success'=>false,'user'=>null]);exit;}
$q=$pdo->prepare("SELECT id,username,full_name,student_id,employee_id,role,status FROM users WHERE id=?");
$q->execute([(int)$_SESSION['user_id']]); $u=$q->fetch();
if(!$u || $u['status']!=='Active'){session_unset();session_destroy();echo json_encode(['success'=>false,'user'=>null]);exit;}
echo json_encode(['success'=>true,'user'=>$u]);
