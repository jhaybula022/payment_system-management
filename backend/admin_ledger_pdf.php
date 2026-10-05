<?php
declare(strict_types=1);
require __DIR__.'/config.php';
require_admin();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo); ensure_user_academic_fields($pdo);

function qpdate(string $v): string { return preg_match('/^\d{4}-\d{2}-\d{2}$/',$v)?$v:''; }
$year=trim((string)($_GET['year_level']??'')); $channel=trim((string)($_GET['channel']??''));
$from=qpdate((string)($_GET['date_from']??'')); $to=qpdate((string)($_GET['date_to']??'')); $search=trim((string)($_GET['search']??''));
$allowedYears=['1st Year','2nd Year','3rd Year','4th Year'];
if(!in_array($year,$allowedYears,true))$year=''; if(!in_array($channel,['Online','Face-to-Face'],true))$channel='';
$where=["u.role='user'"]; $args=[];
if($year!==''){$where[]='u.year_level=?';$args[]=$year;}
if($search!==''){$where[]='(u.full_name LIKE ? OR u.username LIKE ? OR u.student_id LIKE ?)';$like='%'.$search.'%';array_push($args,$like,$like,$like);}
$ws=implode(' AND ',$where);
$q=$pdo->prepare("SELECT u.id,u.full_name,u.student_id,u.year_level FROM users u WHERE $ws ORDER BY CASE u.year_level WHEN '1st Year' THEN 1 WHEN '2nd Year' THEN 2 WHEN '3rd Year' THEN 3 WHEN '4th Year' THEN 4 ELSE 5 END,u.full_name");
$q->execute($args); $users=$q->fetchAll();
$fmt=fn($n)=>number_format((float)$n,2);
$esc=fn($x)=>preg_replace('/[^\x20-\x7E]/','',str_replace(["\\","(",")"],["/","[","]"],(string)$x));
$lines=[];
$lines[]='BESTLINK COLLEGE - STUDENT PAYMENT LEDGER';
$lines[]='Generated: '.date('Y-m-d H:i');
$lines[]='Filters: Year='.($year?:'All').' | Channel='.($channel?:'All').' | From='.($from?:'All').' | To='.($to?:'All');
$lines[]='';
$lines[]='Student ID | Student | Year | Assessment | Paid | Balance';
$lines[]=str_repeat('-',105);
$totalA=$totalP=$totalB=0;
$paymentQ=$pdo->prepare("SELECT p.amount,p.payment_method,p.payment_channel,p.status,p.created_at,p.payment_date,p.reference_number,COALESCE(f.name,'Payment') fee_name FROM payments p LEFT JOIN fee_items f ON f.id=p.fee_item_id WHERE p.user_id=? AND COALESCE(p.payment_date,p.created_at)>=COALESCE(?, '1000-01-01') AND COALESCE(p.payment_date,p.created_at)<DATE_ADD(COALESCE(?, '9999-12-31'), INTERVAL 1 DAY) ORDER BY p.created_at");
foreach($users as $u){
  $f=get_student_financial_summary($pdo,(int)$u['id']); $a=(float)$f['adjusted_assessment'];$p=(float)$f['verified_paid'];$b=(float)$f['outstanding'];
  $totalA+=$a;$totalP+=$p;$totalB+=$b;
  $lines[]=$esc($u['student_id']?:'-').' | '.$esc($u['full_name']).' | '.($esc($u['year_level']?:'Not Set')).' | PHP '.$fmt($a).' | PHP '.$fmt($p).' | PHP '.$fmt($b);
  $paymentQ->execute([(int)$u['id'],$from?:null,$to?:null]); $tx=$paymentQ->fetchAll();
  if($channel!=='')$tx=array_values(array_filter($tx,function($p)use($channel){$c=$p['payment_channel']?:((strcasecmp((string)$p['payment_method'],'Cash')===0)?'Face-to-Face':'Online');return $c===$channel;}));
  foreach($tx as $t){
    $c=$t['payment_channel']?:((strcasecmp((string)$t['payment_method'],'Cash')===0)?'Face-to-Face':'Online');
    $lines[]='  '.$esc($t['payment_date'] ?: $t['created_at']).' | '.$c.' | '.$esc($t['payment_method']).' | PHP '.$fmt($t['amount']).' | '.$esc($t['status']).' | '.$esc($t['reference_number']);
  }
}
$lines[]=''; $lines[]='TOTAL | Assessment PHP '.$fmt($totalA).' | Paid PHP '.$fmt($totalP).' | Outstanding PHP '.$fmt($totalB);

function make_pdf(array $lines): string {
  $pages=array_chunk($lines,48); if(!$pages)$pages=[[]];
  $objects=[]; $offsets=[]; $objects[1]='<< /Type /Catalog /Pages 2 0 R >>';
  $kids=[]; $obj=3;
  foreach($pages as $pi=>$pageLines){$pageObj=$obj++;$contentObj=$obj++;$kids[]=$pageObj.' 0 R';
    $stream="BT\n/F1 8 Tf\n40 555 Td\n10 TL\n";
    foreach($pageLines as $line){$safe=preg_replace('/[^\x20-\x7E]/',' ',(string)$line);$safe=str_replace(['\\','(',')'],['\\\\','\\(','\\)'],$safe);$stream.="(".$safe.") Tj\nT*\n";}
    $stream.="ET";
    $objects[$pageObj]='<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 '.($obj+count($pages)*2).' 0 R >> >> /Contents '.$contentObj.' 0 R >>';
    $objects[$contentObj]='<< /Length '.strlen($stream)." >>\nstream\n".$stream."\nendstream";
  }
  $fontObj=3+(count($pages)*2);
  // Re-point font references deterministically.
  foreach($kids as $k){} 
  $objects[2]='<< /Type /Pages /Kids ['.implode(' ',$kids).'] /Count '.count($kids).' >>';
  $objects[$fontObj]='<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
  ksort($objects); $pdf="%PDF-1.4\n"; $offsets=[0];
  $max=max(array_keys($objects));
  for($i=1;$i<=$max;$i++){if(!isset($objects[$i]))continue;$offsets[$i]=strlen($pdf);$pdf.=$i." 0 obj\n".$objects[$i]."\nendobj\n";}
  $xref=strlen($pdf);$pdf.="xref\n0 ".($max+1)."\n0000000000 65535 f \n";
  for($i=1;$i<=$max;$i++)$pdf.=sprintf("%010d 00000 n \n",$offsets[$i]??0);
  $pdf.="trailer\n<< /Size ".($max+1)." /Root 1 0 R >>\nstartxref\n".$xref."\n%%EOF";
  return $pdf;
}
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="student_payment_ledger_'.date('Ymd_His').'.pdf"');
echo make_pdf($lines);
