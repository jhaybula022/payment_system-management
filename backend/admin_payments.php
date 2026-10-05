<?php
require __DIR__.'/config.php'; require_admin();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);
$q=$pdo->query("SELECT p.id,p.amount,p.payment_method,p.payment_channel,p.reference_number,p.proof_file_name,p.status,p.created_at,p.payment_date,p.verified_at,p.cashier_id,COALESCE(c.full_name,'') AS cashier_name,COALESCE(c.employee_id,'') AS cashier_employee_id,p.fee_item_id,p.installment_id,COALESCE(pi.name,'') AS installment_name,COALESCE(f.name,'Unassigned') AS fee_name,u.username,u.full_name,u.student_id
FROM payments p INNER JOIN users u ON u.id=p.user_id LEFT JOIN fee_items f ON f.id=p.fee_item_id LEFT JOIN payment_installments pi ON pi.id=p.installment_id LEFT JOIN users c ON c.id=p.cashier_id ORDER BY p.created_at DESC");
echo json_encode(['success'=>true,'payments'=>$q->fetchAll()]);
