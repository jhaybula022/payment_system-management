<?php
require __DIR__.'/config.php';
require_admin(); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
if($_SERVER['REQUEST_METHOD']==='GET'){
  $from=trim((string)($_GET['date_from']??'')); $to=trim((string)($_GET['date_to']??'')); $search=trim((string)($_GET['search']??''));
  $where=['1=1'];$args=[];
  if($from!==''){$where[]='DATE(COALESCE(p.payment_date,p.created_at))>=?';$args[]=$from;}
  if($to!==''){$where[]='DATE(COALESCE(p.payment_date,p.created_at))<=?';$args[]=$to;}
  if($search!==''){$where[]='(u.full_name LIKE ? OR u.student_id LIKE ? OR p.reference_number LIKE ?)';$like='%'.$search.'%';array_push($args,$like,$like,$like);}
  $q=$pdo->prepare("SELECT p.id,p.amount,p.payment_method,p.payment_channel,p.reference_number,p.status,p.payment_date,p.created_at,u.full_name,u.student_id,COALESCE(c.full_name,'') cashier_name FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN users c ON c.id=p.cashier_id WHERE ".implode(' AND ',$where)." ORDER BY COALESCE(p.payment_date,p.created_at) DESC,p.id DESC LIMIT 500");
  $q->execute($args); echo json_encode(['success'=>true,'payments'=>$q->fetchAll()]); exit;
}
$in=json_decode(file_get_contents('php://input'),true) ?: [];
$id=(int)($in['id']??0);$date=trim((string)($in['payment_date']??''));
if($id<1 || !preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Please provide a valid payment date.']);exit;}
try{
  $q=$pdo->prepare('UPDATE payments SET payment_date=? WHERE id=?');$q->execute([$date,$id]);
  $aq=$pdo->prepare('INSERT INTO audit_logs(user_id,action) VALUES(?,?)');$aq->execute([(int)$_SESSION['user_id'],'Updated payment #'.$id.' payment date to '.$date]);
  echo json_encode(['success'=>true,'message'=>'Payment date updated.']);
}catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Unable to update payment date.']);}
