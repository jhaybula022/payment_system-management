<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$admin = require_admin();
ensure_payments_table($pdo);
ensure_user_academic_fields($pdo);
ensure_payment_modules_tables($pdo);

function module_users(PDO $pdo): array {
  return $pdo->query("SELECT id,username,full_name,student_id,year_level,status FROM users WHERE role='user' ORDER BY full_name, id")->fetchAll();
}
function module_scholarships(PDO $pdo): array {
  $q=$pdo->query("SELECT s.id,s.user_id,s.request_type,s.discount_percent,s.reason,s.student_message,s.proof_file_name,s.proof_uploaded_at,s.status,s.created_at,s.reviewed_at,s.reviewed_by,u.username,u.full_name,u.student_id,ru.username AS reviewed_username,ru.full_name AS reviewed_name
    FROM scholarship_applications s
    INNER JOIN users u ON u.id=s.user_id
    LEFT JOIN users ru ON ru.id=s.reviewed_by
    ORDER BY s.created_at DESC");
  return $q->fetchAll();
}
function module_installments(PDO $pdo): array { return $pdo->query("SELECT id,name,percentage,due_date,active,sort_order FROM payment_installments ORDER BY sort_order,id")->fetchAll(); }
function module_audit(PDO $pdo): array {
  $q=$pdo->query("SELECT a.id,a.action,a.created_at,u.username,u.full_name FROM audit_logs a LEFT JOIN users u ON u.id=a.user_id ORDER BY a.created_at DESC LIMIT 50");
  return $q->fetchAll();
}

if($_SERVER['REQUEST_METHOD']==='GET'){
  $payments=$pdo->query("SELECT p.id,p.user_id,p.amount,p.payment_method,p.payment_channel,p.reference_number,p.proof_file_name,p.installment_id,COALESCE(pi.name,'') AS installment_name,p.status,p.created_at,p.verified_at,u.username,u.full_name,u.student_id FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN payment_installments pi ON pi.id=p.installment_id ORDER BY p.created_at DESC")->fetchAll();
  $methods=[];
  foreach($payments as $p){$m=$p['payment_method'] ?: 'Unknown'; if(!isset($methods[$m]))$methods[$m]=['count'=>0,'amount'=>0];$methods[$m]['count']++;$methods[$m]['amount']+=(float)$p['amount'];}
  $users=module_users($pdo);
  $financial=[];
  foreach($users as $u){$financial[(string)$u['id']]=get_student_financial_summary($pdo,(int)$u['id']);}
  echo json_encode(['success'=>true,
    'installments'=>module_installments($pdo),
    'settings'=>[
      'default_assessment'=>(float)get_payment_setting($pdo,'default_assessment','6175'),
      'due_date'=>get_payment_setting($pdo,'due_date','2026-08-30'),
      'penalty_percent'=>(float)get_payment_setting($pdo,'penalty_percent','2')
    ],
    'users'=>$users,
    'payments'=>$payments,
    'payment_methods'=>$methods,
    'scholarships'=>module_scholarships($pdo),
    'financial'=>$financial,
    'audit_logs'=>module_audit($pdo)
  ]); exit;
}

$in=json_decode(file_get_contents('php://input'),true) ?: [];
$action=(string)($in['action']??'');
try{
  if($action==='save_installments'){
    $items=$in['installments']??[];
    if(!is_array($items)||count($items)<1)throw new InvalidArgumentException('At least one payment period is required.');
    $clean=[];$sum=0;$names=[];
    foreach(array_values($items) as $i=>$item){
      $id=(int)($item['id']??0); $name=trim((string)($item['name']??'')); $pct=(float)($item['percentage']??0); $due=trim((string)($item['due_date']??''));
      if($name===''||$pct<=0||$pct>100)throw new InvalidArgumentException('Each payment period needs a valid name and percentage.');
      if(isset($names[strtolower($name)]))throw new InvalidArgumentException('Payment period names must be unique.'); $names[strtolower($name)]=true;
      if($due!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))throw new InvalidArgumentException('Invalid payment period due date.');
      $clean[]=['id'=>$id,'name'=>$name,'percentage'=>$pct,'due_date'=>$due,'sort_order'=>$i+1]; $sum+=$pct;
    }
    if(abs($sum-100)>0.01)throw new InvalidArgumentException('Prelim, Midterm, and Finals percentages must total exactly 100%.');
    $pdo->beginTransaction();
    try{
      $up=$pdo->prepare("UPDATE payment_installments SET name=?,percentage=?,due_date=?,active=1,sort_order=? WHERE id=?");
      $ins=$pdo->prepare("INSERT INTO payment_installments(name,percentage,due_date,active,sort_order) VALUES(?,?,?,1,?)");
      $seen=[];
      foreach($clean as $row){ if($row['id']>0){$up->execute([$row['name'],$row['percentage'],$row['due_date']!==''?$row['due_date']:null,$row['sort_order'],$row['id']]);$seen[]=$row['id'];} else {$ins->execute([$row['name'],$row['percentage'],$row['due_date']!==''?$row['due_date']:null,$row['sort_order']]);$seen[]=(int)$pdo->lastInsertId();} }
      if($seen){$inList=implode(',',array_fill(0,count($seen),'?'));$pdo->prepare("UPDATE payment_installments SET active=0 WHERE id NOT IN ($inList)")->execute($seen);}
      $pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)")->execute([(int)$admin['user_id'],'Updated Prelim/Midterm/Finals payment schedule']);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    echo json_encode(['success'=>true,'message'=>'Payment period schedule saved.']); exit;
  }
  if($action==='save_settings'){
    $map=[
      'default_assessment'=>(string)max(0,(float)($in['default_assessment']??0)),
      'due_date'=>(string)($in['due_date']??''),
      'penalty_percent'=>'0'
    ];
    if($map['due_date']!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$map['due_date'])) throw new InvalidArgumentException('Invalid due date.');
    $q=$pdo->prepare("INSERT INTO payment_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)");
    foreach($map as $k=>$v)$q->execute([$k,$v,(int)$admin['user_id']]);
    $q=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");
    $q->execute([(int)$admin['user_id'],'Updated payment settings']);
    echo json_encode(['success'=>true,'message'=>'Payment settings saved.']); exit;
  }
  if($action==='add_scholarship'){ throw new InvalidArgumentException('Scholarship discounts must be submitted by the student with a certificate/proof.'); }
  if($action==='review_scholarship'){
    $id=(int)($in['id']??0);$status=(string)($in['status']??'');
    if($id<1||!in_array($status,['Approved','Rejected'],true))throw new InvalidArgumentException('Invalid scholarship review.');
    $q=$pdo->prepare("SELECT id,proof_file_name FROM scholarship_applications WHERE id=? LIMIT 1");$q->execute([$id]);$sch=$q->fetch();if(!$sch)throw new InvalidArgumentException('Scholarship application not found.'); if($status==='Approved' && empty($sch['proof_file_name'])) throw new InvalidArgumentException('A scholarship/discount cannot be approved without a certificate or proof.');
    $q=$pdo->prepare("UPDATE scholarship_applications SET status=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?");$q->execute([$status,(int)$admin['user_id'],$id]);
    $q=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");$q->execute([(int)$admin['user_id'],'Scholarship application #'.$id.' marked '.$status]);
    echo json_encode(['success'=>true,'message'=>'Scholarship application updated.']); exit;
  }
  if($action==='audit'){
    $msg=trim((string)($in['message']??''));if($msg==='')throw new InvalidArgumentException('Audit message is required.');
    $q=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)");$q->execute([(int)$admin['user_id'],substr($msg,0,255)]);
    echo json_encode(['success'=>true]);exit;
  }
  throw new InvalidArgumentException('Unknown module action.');
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){http_response_code(500);echo json_encode(['success'=>false,'message'=>'Payment module database error.']);}
