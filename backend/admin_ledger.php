<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_admin();
ensure_payments_table($pdo);
ensure_payment_modules_tables($pdo);
ensure_user_academic_fields($pdo);

function ledger_channel(array $p): string {
  $c=trim((string)($p['payment_channel']??''));
  if(in_array($c,['Online','Face-to-Face'],true)) return $c;
  return strcasecmp((string)($p['payment_method']??''),'Cash')===0 ? 'Face-to-Face' : 'Online';
}
function ledger_date(string $v): string {
  return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v) ? $v : '';
}
$year=trim((string)($_GET['year_level']??''));
$channel=trim((string)($_GET['channel']??''));
$from=ledger_date((string)($_GET['date_from']??''));
$to=ledger_date((string)($_GET['date_to']??''));
$search=trim((string)($_GET['search']??''));
$page=max(1,(int)($_GET['page']??1));
$perPage=min(50,max(5,(int)($_GET['per_page']??10)));
$allowedYears=['1st Year','2nd Year','3rd Year','4th Year'];
if($year!==''&&!in_array($year,$allowedYears,true))$year='';
if($channel!==''&&!in_array($channel,['Online','Face-to-Face'],true))$channel='';

$where=["u.role='user'"];
$args=[];
if($year!==''){ $where[]='u.year_level=?'; $args[]=$year; }
if($search!==''){ $where[]='(u.full_name LIKE ? OR u.username LIKE ? OR u.student_id LIKE ?)'; $like='%'.$search.'%'; array_push($args,$like,$like,$like); }
$whereSql=implode(' AND ',$where);

$countQ=$pdo->prepare("SELECT COUNT(*) FROM users u WHERE $whereSql"); $countQ->execute($args);
$total=(int)$countQ->fetchColumn();
$pages=max(1,(int)ceil($total/$perPage)); if($page>$pages)$page=$pages;
$offset=($page-1)*$perPage;

$uq=$pdo->prepare("SELECT u.id,u.username,u.full_name,u.student_id,u.year_level,u.status FROM users u WHERE $whereSql ORDER BY CASE u.year_level WHEN '1st Year' THEN 1 WHEN '2nd Year' THEN 2 WHEN '3rd Year' THEN 3 WHEN '4th Year' THEN 4 ELSE 5 END,u.full_name,u.id LIMIT $perPage OFFSET $offset");
$uq->execute($args);
$students=$uq->fetchAll();

$paymentSql="SELECT p.user_id,p.amount,p.payment_method,p.payment_channel,p.status,p.created_at,p.payment_date,p.reference_number,COALESCE(f.name,'Payment') fee_name
 FROM payments p LEFT JOIN fee_items f ON f.id=p.fee_item_id
 WHERE p.user_id=? AND COALESCE(p.payment_date,p.created_at)>=COALESCE(?, '1000-01-01') AND COALESCE(p.payment_date,p.created_at)<DATE_ADD(COALESCE(?, '9999-12-31'), INTERVAL 1 DAY)
 ORDER BY p.created_at DESC";
$paymentQ=$pdo->prepare($paymentSql);
$rows=[];
$onlineTotal=$faceTotal=0.0;
foreach($students as $u){
  $summary=get_student_financial_summary($pdo,(int)$u['id']);
  $paymentQ->execute([(int)$u['id'],$from?:null,$to?:null]);
  $tx=$paymentQ->fetchAll();
  if($channel!=='') $tx=array_values(array_filter($tx,fn($p)=>ledger_channel($p)===$channel));
  $verifiedInFilter=0.0;
  foreach($tx as $p) if($p['status']==='Verified') {
    $verifiedInFilter+=(float)$p['amount'];
    if(ledger_channel($p)==='Online')$onlineTotal+=(float)$p['amount']; else $faceTotal+=(float)$p['amount'];
  }
  $rows[]=[
    'id'=>(int)$u['id'],'student_id'=>$u['student_id'],'full_name'=>$u['full_name'],'username'=>$u['username'],
    'year_level'=>$u['year_level']?:'Not Set','status'=>$u['status'],
    'assessment'=>(float)$summary['adjusted_assessment'],
    'total_paid'=>(float)$summary['verified_paid'],'outstanding'=>(float)$summary['outstanding'],
    'pending'=>(float)$summary['pending_paid'],'due_date'=>$summary['due_date'],
    'filtered_verified_paid'=>$verifiedInFilter,'transactions'=>$tx
  ];
}
$allAssessment=$allPaid=$allOutstanding=$allPending=0.0;
foreach($rows as $r){$allAssessment+=$r['assessment'];$allPaid+=$r['total_paid'];$allOutstanding+=$r['outstanding'];$allPending+=$r['pending'];}
echo json_encode(['success'=>true,'students'=>$rows,'pagination'=>['page'=>$page,'per_page'=>$perPage,'total'=>$total,'pages'=>$pages],
 'filters'=>['year_level'=>$year,'channel'=>$channel,'date_from'=>$from,'date_to'=>$to,'search'=>$search],
 'totals'=>['assessment'=>$allAssessment,'paid'=>$allPaid,'outstanding'=>$allOutstanding,'pending'=>$allPending,'online_verified'=>$onlineTotal,'face_to_face_verified'=>$faceTotal]]);
