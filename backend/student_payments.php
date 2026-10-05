<?php
require __DIR__.'/config.php';
$s = require_login();
ensure_payments_table($pdo); ensure_payment_modules_tables($pdo);

$q = $pdo->prepare("SELECT p.id, p.fee_item_id, p.installment_id, COALESCE(pi.name,'') AS installment_name, COALESCE(f.name,'Unassigned') AS fee_name, p.amount, p.payment_method, p.reference_number, p.proof_file_name, p.status, p.created_at, p.payment_date, p.verified_at, p.payment_channel, p.cashier_id, COALESCE(c.full_name,'') AS cashier_name, COALESCE(c.employee_id,'') AS cashier_employee_id, p.provider_payment_id, p.provider_status, p.payment_expires_at, p.receipt_number, p.receipt_file_name
                    FROM payments p LEFT JOIN fee_items f ON f.id=p.fee_item_id LEFT JOIN payment_installments pi ON pi.id=p.installment_id LEFT JOIN users c ON c.id=p.cashier_id WHERE p.user_id=? ORDER BY created_at DESC");
$q->execute([(int)$s['user_id']]);

$payments = $q->fetchAll();

// Return only the logged-in student's scholarship/discount records.
$scholarshipQuery = $pdo->prepare("SELECT id, request_type, discount_percent, reason, student_message, proof_file_name, proof_uploaded_at, status, created_at, reviewed_at FROM scholarship_applications WHERE user_id=? ORDER BY created_at DESC");
$scholarshipQuery->execute([(int)$s['user_id']]);
$scholarshipApplications = $scholarshipQuery->fetchAll();

// Student account computation uses the same financial engine as the admin modules.
$summary = get_student_financial_summary($pdo, (int)$s['user_id']);
$cashiers = $pdo->query("SELECT id,full_name,employee_id FROM users WHERE role='cashier' AND status='Active' ORDER BY full_name,id")->fetchAll();
echo json_encode([
  'success'=>true,
  'payments'=>$payments,
  'fee_items'=>get_fee_items($pdo),
  'summary'=>[
    'total_assessment'=>$summary['total_assessment'],
    'total_paid'=>$summary['verified_paid'],
    'verified_paid'=>$summary['verified_paid'],
    'pending_paid'=>$summary['pending_paid'],
    'discount_percent'=>$summary['discount_percent'],
    'discount_amount'=>$summary['discount_amount'],
    'adjusted_assessment'=>$summary['adjusted_assessment'],
    'penalty_percent'=>$summary['penalty_percent'],
    'penalty_amount'=>$summary['penalty_amount'],
    'due_date'=>$summary['due_date'],
    'is_overdue'=>$summary['is_overdue'],
    'outstanding'=>$summary['outstanding'],
    'progress'=>$summary['progress'],
    'fee_breakdown'=>$summary['fee_breakdown'],
    'installments'=>$summary['installments']
  ],
  'scholarship_applications'=>$scholarshipApplications,
  'cashiers'=>$cashiers
]);
