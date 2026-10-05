<?php
require __DIR__.'/config.php';
require_admin();
ensure_user_academic_fields($pdo); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
$cashiers=$pdo->query("SELECT id,username,full_name,employee_id,status,created_at FROM users WHERE role='cashier' ORDER BY full_name,id")->fetchAll();
foreach($cashiers as &$c){
  $q=$pdo->prepare("SELECT COUNT(*) tx, COALESCE(SUM(CASE WHEN p.status='Verified' THEN p.amount ELSE 0 END),0) collected, MAX(p.created_at) last_tx FROM payments p WHERE p.cashier_id=?");
  $q->execute([(int)$c['id']]); $m=$q->fetch() ?: [];
  $c['transactions']=(int)($m['tx']??0); $c['collected']=(float)($m['collected']??0); $c['last_transaction']=$m['last_tx']??null;
}
unset($c);
$from=trim((string)($_GET['date_from']??'')); $to=trim((string)($_GET['date_to']??'')); $cashier=(int)($_GET['cashier_id']??0); $search=trim((string)($_GET['search']??''));
$where=["p.cashier_id IS NOT NULL"]; $args=[];
if($cashier>0){$where[]='p.cashier_id=?';$args[]=$cashier;}
if($from!==''){$where[]='DATE(COALESCE(p.payment_date,p.created_at))>=?';$args[]=$from;}
if($to!==''){$where[]='DATE(COALESCE(p.payment_date,p.created_at))<=?';$args[]=$to;}
if($search!==''){$where[]='(u.full_name LIKE ? OR u.student_id LIKE ? OR p.reference_number LIKE ?)';$args[]="%$search%";$args[]="%$search%";$args[]="%$search%";}
$ws=implode(' AND ',$where);
$q=$pdo->prepare("SELECT p.id,p.amount,p.payment_method,p.payment_channel,p.reference_number,p.status,p.created_at,p.payment_date,p.processed_at,u.full_name student_name,u.student_id,c.full_name cashier_name,c.employee_id cashier_employee_id FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN users c ON c.id=p.cashier_id WHERE $ws ORDER BY p.created_at DESC LIMIT 500");
$q->execute($args); $records=$q->fetchAll();
echo json_encode(['success'=>true,'cashiers'=>$cashiers,'records'=>$records,'filters'=>['date_from'=>$from,'date_to'=>$to,'cashier_id'=>$cashier,'search'=>$search]]);
