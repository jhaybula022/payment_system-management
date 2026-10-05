<?php
require __DIR__.'/config.php';
$s=require_cashier(); ensure_user_academic_fields($pdo); ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
$students=$pdo->query("SELECT id,username,full_name,student_id,year_level,status FROM users WHERE role='user' AND status='Active' ORDER BY full_name,id")->fetchAll();
$fees=$pdo->query("SELECT id,name,percentage,fixed_amount FROM fee_items WHERE active=1 ORDER BY id")->fetchAll();
$q=$pdo->prepare("SELECT p.id,p.amount,p.payment_method,p.payment_channel,p.reference_number,p.status,p.proof_file_name,p.created_at,p.payment_date,p.processed_at,p.verified_at,p.receipt_number,p.receipt_file_name,COALESCE(f.name,'General Payment') AS fee_name,COALESCE(pi.name,'') AS installment_name,u.full_name student_name,u.student_id FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN fee_items f ON f.id=p.fee_item_id LEFT JOIN payment_installments pi ON pi.id=p.installment_id WHERE p.cashier_id=? ORDER BY COALESCE(p.payment_date,p.created_at) DESC,p.id DESC LIMIT 200");
$q->execute([(int)$s['user_id']]);$records=$q->fetchAll();
$pending=array_values(array_filter($records,fn($r)=>in_array($r['status'],['Pending Verification','Pending Online Confirmation'],true)));
$today=date('Y-m-d');
// Today's Transactions = payments created today. Today's Collection = payments actually verified today.
$todayRows=array_values(array_filter($records,fn($r)=>substr((string)($r['created_at']??''),0,10)===$today));
$todayCollection=0;$verified=0;$rejected=0;$outstanding=0;
foreach($records as $r) if($r['status']==='Verified' && substr((string)($r['verified_at']??''),0,10)===$today) $todayCollection+=(float)$r['amount'];
foreach($records as $r){if($r['status']==='Verified')$verified++;if($r['status']==='Rejected')$rejected++;}
$online=array_values(array_filter($records,fn($r)=>$r['payment_channel']==='Online'));
$face=array_values(array_filter($records,fn($r)=>$r['payment_channel']==='Face-to-Face'));
$verifiedAmount=0; foreach($records as $r) if($r['status']==='Verified') $verifiedAmount+=(float)$r['amount'];
// Compute receivables using the same financial engine so scholarships/fees remain consistent.
foreach($students as $st){$sum=get_student_financial_summary($pdo,(int)$st['id']);$outstanding+=(float)$sum['outstanding'];}
echo json_encode(['success'=>true,'cashier'=>['id'=>(int)$s['user_id'],'username'=>$s['username']??'','full_name'=>$s['full_name']??'','employee_id'=>$s['employee_id']??''],'students'=>$students,'fees'=>$fees,'records'=>$records,'pending'=>$pending,'online_history'=>$online,'face_to_face_history'=>$face,'stats'=>['today_collection'=>$todayCollection,'today_transactions'=>count($todayRows),'pending_payments'=>count($pending),'verified_payments'=>$verified,'rejected_payments'=>$rejected,'outstanding_receivables'=>$outstanding,'verified_collection'=>$verifiedAmount]]);
