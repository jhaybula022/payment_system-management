<?php
declare(strict_types=1);

// Secure session settings. Secure is enabled automatically when HTTPS is used;
// localhost/XAMPP over plain HTTP remains compatible.
$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443);
session_set_cookie_params([
  'lifetime' => 0,
  'path' => '/',
  'secure' => $https,
  'httponly' => true,
  'samesite' => 'Lax'
]);
session_start();

// Security headers that do not alter the existing UI or frontend behavior.
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');


// Docker Compose: DB_HOST=db. XAMPP: DB_HOST=localhost.
$host = getenv('DB_HOST') ?: (getenv('DOCKER_ENV') ? 'db' : 'localhost');
$db = getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'bestlink_student_system');
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : ((getenv('DOCKER_ENV')) ? 'root' : '');
$port = (int)(getenv('DB_PORT') ?: 3306);
$charset = 'utf8mb4';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Keep API endpoints JSON even when a PHP exception/fatal error occurs.
set_exception_handler(function(Throwable $e){
  error_log('Payment System API exception: '.$e->getMessage());
  if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'A server error occurred while processing the request. Check the PHP error log for details.']);
  exit;
});
register_shutdown_function(function(){
  $e=error_get_last();
  if($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)){
    error_log('Payment System PHP fatal: '.$e['message'].' in '.$e['file'].' on line '.$e['line']);
    if(!headers_sent()) header('Content-Type: application/json; charset=utf-8');
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'A PHP server error occurred. Check the PHP error log for details.']);
  }
});

try {
  $pdo = new PDO("mysql:host=$host;port=$port;dbname=$db;charset=$charset", $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ]);
} catch (Throwable $e) {
  error_log('Payment System DB connection error: ' . $e->getMessage());
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Database connection failed. Check DB_HOST, DB_DATABASE, DB_USER, DB_PASS, and DB_PORT.']);
  exit;
}

function require_login(): array {
  $timeout = 300; // 5 minutes of inactivity
  if(empty($_SESSION['user_id'])){
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Please log in first.']); exit;
  }

  $now = time();
  $last = (int)($_SESSION['last_activity'] ?? 0);
  if($last > 0 && ($now - $last) > $timeout){
    $_SESSION = [];
    if(ini_get('session.use_cookies')) {
      $params = session_get_cookie_params();
      setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
    }
    session_destroy();
    http_response_code(401);
    echo json_encode(['success'=>false,'message'=>'Your session expired after 5 minutes of inactivity. Please log in again.']); exit;
  }

  // Re-check account status from the database so an Admin deactivation takes effect
  // immediately instead of waiting for the old session to expire.
  try {
    $q=$GLOBALS['pdo']->prepare('SELECT role,status,employee_id FROM users WHERE id=? LIMIT 1');
    $q->execute([(int)$_SESSION['user_id']]);
    $u=$q->fetch();
    if(!$u || ($u['status'] ?? '') !== 'Active'){
      $_SESSION=[]; session_destroy();
      http_response_code(403);
      echo json_encode(['success'=>false,'message'=>'This account is inactive or no longer available.']); exit;
    }
    $_SESSION['role']=$u['role'];
    $_SESSION['employee_id']=$u['employee_id']??'';
  } catch(Throwable $e) {
    http_response_code(500);
    echo json_encode(['success'=>false,'message'=>'Unable to verify the account session.']); exit;
  }

  $_SESSION['last_activity']=$now;
  return $_SESSION;
}


function ensure_user_academic_fields(PDO $pdo): void {
  // Backward-compatible migration: existing student accounts are preserved.
  $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME=?");
  $check->execute(['year_level']);
  if (!(int)$check->fetchColumn()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN year_level VARCHAR(30) NULL AFTER student_id");
  }
  $check->execute(['employee_id']);
  if (!(int)$check->fetchColumn()) {
    $pdo->exec("ALTER TABLE users ADD COLUMN employee_id VARCHAR(60) NULL AFTER year_level");
  }
  // Allow a dedicated cashier role while preserving existing admin/user roles.
  try { $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','user','cashier') NOT NULL DEFAULT 'user'"); } catch (Throwable $e) { /* legacy DBs may already use a compatible type */ }

}

function ensure_payments_table(PDO $pdo): void {
  ensure_user_academic_fields($pdo);
  $pdo->exec("CREATE TABLE IF NOT EXISTS payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    fee_item_id INT UNSIGNED NULL,
    installment_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(100) NOT NULL,
    reference_number VARCHAR(100) NOT NULL,
    proof_file_name VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending Verification',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    provider_payment_id VARCHAR(120) NULL,
    provider_status VARCHAR(60) NULL,
    payment_expires_at DATETIME NULL,
    receipt_number VARCHAR(120) NULL,
    receipt_file_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_user (user_id),
    INDEX idx_pay_status (status),
    INDEX idx_pay_reference (reference_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $columns = [
    'user_id' => "ALTER TABLE payments ADD COLUMN user_id INT UNSIGNED NOT NULL AFTER id",
    'fee_item_id' => "ALTER TABLE payments ADD COLUMN fee_item_id INT UNSIGNED NULL AFTER user_id",
    'amount' => "ALTER TABLE payments ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER user_id",
    'payment_method' => "ALTER TABLE payments ADD COLUMN payment_method VARCHAR(100) NOT NULL DEFAULT '' AFTER amount",
    'reference_number' => "ALTER TABLE payments ADD COLUMN reference_number VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_method",
    'proof_file_name' => "ALTER TABLE payments ADD COLUMN proof_file_name VARCHAR(255) NULL AFTER reference_number",
    'status' => "ALTER TABLE payments ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'Pending Verification' AFTER proof_file_name",
    'verified_by' => "ALTER TABLE payments ADD COLUMN verified_by INT UNSIGNED NULL AFTER status",
    'verified_at' => "ALTER TABLE payments ADD COLUMN verified_at DATETIME NULL AFTER verified_by",
    'provider_payment_id' => "ALTER TABLE payments ADD COLUMN provider_payment_id VARCHAR(120) NULL AFTER verified_at",
    'provider_status' => "ALTER TABLE payments ADD COLUMN provider_status VARCHAR(60) NULL AFTER provider_payment_id",
    'payment_expires_at' => "ALTER TABLE payments ADD COLUMN payment_expires_at DATETIME NULL AFTER provider_status",
    'receipt_number' => "ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(120) NULL AFTER payment_expires_at",
    'installment_id' => "ALTER TABLE payments ADD COLUMN installment_id INT UNSIGNED NULL AFTER fee_item_id",
    'payment_channel' => "ALTER TABLE payments ADD COLUMN payment_channel VARCHAR(30) NOT NULL DEFAULT 'Online' AFTER payment_method",
    'cashier_id' => "ALTER TABLE payments ADD COLUMN cashier_id INT UNSIGNED NULL AFTER payment_channel",
    'processed_at' => "ALTER TABLE payments ADD COLUMN processed_at DATETIME NULL AFTER cashier_id",
    'payment_date' => "ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER processed_at",
    'receipt_file_name' => "ALTER TABLE payments ADD COLUMN receipt_file_name VARCHAR(255) NULL AFTER receipt_number",
    'created_at' => "ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER receipt_file_name"
  ];

  $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'payments' AND COLUMN_NAME = ?");
  foreach ($columns as $name => $sql) {
    $check->execute([$name]);
    if (!(int)$check->fetchColumn()) {
      $pdo->exec($sql);
    }
  }
  // Preserve historical meaning: Cash transactions are face-to-face.
  $pdo->exec("UPDATE payments SET payment_channel='Face-to-Face' WHERE LOWER(TRIM(payment_method))='cash' AND (payment_channel IS NULL OR payment_channel='' OR payment_channel='Online')");
}


function ensure_payment_webhook_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_webhook_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id VARCHAR(120) NOT NULL UNIQUE,
    event_type VARCHAR(80) NOT NULL,
    livemode TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function ensure_payment_modules_tables(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_settings (
    setting_key VARCHAR(80) PRIMARY KEY,
    setting_value TEXT NULL,
    updated_by INT UNSIGNED NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS scholarship_applications (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    request_type VARCHAR(80) NOT NULL DEFAULT 'Discount Request',
    discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0,
    reason VARCHAR(255) NOT NULL,
    student_message VARCHAR(1000) NULL,
    status ENUM('For Review','Approved','Rejected') NOT NULL DEFAULT 'For Review',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    reviewed_by INT UNSIGNED NULL,
    reviewed_at DATETIME NULL,
    INDEX idx_scholar_user(user_id), INDEX idx_scholar_status(status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $scholarCols = [
    'request_type' => "ALTER TABLE scholarship_applications ADD COLUMN request_type VARCHAR(80) NOT NULL DEFAULT 'Discount Request' AFTER user_id",
    'student_message' => "ALTER TABLE scholarship_applications ADD COLUMN student_message VARCHAR(1000) NULL AFTER reason",
    'proof_file_name' => "ALTER TABLE scholarship_applications ADD COLUMN proof_file_name VARCHAR(255) NULL AFTER student_message",
    'proof_uploaded_at' => "ALTER TABLE scholarship_applications ADD COLUMN proof_uploaded_at DATETIME NULL AFTER proof_file_name"
  ];
  $scholarCheck = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scholarship_applications' AND COLUMN_NAME=?");
  foreach($scholarCols as $name=>$sql){ $scholarCheck->execute([$name]); if(!(int)$scholarCheck->fetchColumn()) $pdo->exec($sql); }
  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_installments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(40) NOT NULL UNIQUE,
    percentage DECIMAL(6,3) NOT NULL DEFAULT 0,
    due_date DATE NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    sort_order INT NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $defaultsInstallments=[['Prelim',30.000,'2026-09-30',1],['Midterm',30.000,'2026-11-15',2],['Finals',40.000,'2027-01-15',3]];
  $iq=$pdo->prepare("INSERT IGNORE INTO payment_installments(name,percentage,due_date,sort_order) VALUES(?,?,?,?)");
  foreach($defaultsInstallments as $it)$iq->execute($it);
  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_created(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  ensure_fee_items_table($pdo);
  $defaults = [
    'default_assessment'=>'6175',
    'due_date'=>'',
    'penalty_percent'=>'0',
    'hellomoney_mode'=>'demo',
    'hellomoney_merchant_name'=>'Bestlink College of the Philippines',
    'hellomoney_qr_payload'=>''
  ];
  $q=$pdo->prepare("INSERT IGNORE INTO payment_settings(setting_key,setting_value) VALUES(?,?)");
  foreach($defaults as $k=>$v) $q->execute([$k,$v]);
  $pdo->exec("UPDATE payment_settings SET setting_value='0' WHERE setting_key='penalty_percent'");
}

function ensure_receivable_documents_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS receivable_documents (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    original_name VARCHAR(255) NOT NULL,
    stored_name VARCHAR(255) NOT NULL,
    mime_type VARCHAR(100) NOT NULL DEFAULT 'application/pdf',
    file_size INT UNSIGNED NOT NULL DEFAULT 0,
    uploaded_by INT UNSIGNED NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_receivable_docs_created(created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function get_payment_setting(PDO $pdo, string $key, string $fallback=''): string {
  ensure_payment_modules_tables($pdo);
  $q=$pdo->prepare("SELECT setting_value FROM payment_settings WHERE setting_key=? LIMIT 1");
  $q->execute([$key]);
  $v=$q->fetchColumn();
  // Repair legacy rows that exist but contain an empty/null value. This makes the
  // configured due date available consistently to every module and student portal.
  if ($v === false || trim((string)$v) === '') {
    if ($fallback !== '') {
      $up=$pdo->prepare("INSERT INTO payment_settings(setting_key,setting_value) VALUES(?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value)");
      $up->execute([$key,$fallback]);
    }
    return $fallback;
  }
  return (string)$v;
}



function generate_payment_receipt(PDO $pdo, int $paymentId): ?string {
  ensure_payments_table($pdo);
  $q=$pdo->prepare("SELECT p.*, COALESCE(f.name,'Payment') AS fee_name, COALESCE(pi.name,'Not specified') AS installment_name, u.full_name, u.student_id
                    FROM payments p
                    INNER JOIN users u ON u.id=p.user_id
                    LEFT JOIN fee_items f ON f.id=p.fee_item_id
                    LEFT JOIN payment_installments pi ON pi.id=p.installment_id
                    WHERE p.id=? LIMIT 1");
  $q->execute([$paymentId]);
  $p=$q->fetch();
  if(!$p) return null;

  $receiptNumber=(string)($p['receipt_number']??'');
  if($receiptNumber===''){
    $receiptNumber='REC-'.date('Ymd').'-'.strtoupper(bin2hex(random_bytes(3)));
    $up=$pdo->prepare("UPDATE payments SET receipt_number=? WHERE id=?");
    $up->execute([$receiptNumber,$paymentId]);
  }

  $dir=__DIR__.'/uploads/payment_receipts';
  if(!is_dir($dir)) @mkdir($dir,0755,true);
  $fileName=$receiptNumber.'.html';
  $path=$dir.'/'.$fileName;
  {
    $esc=function($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');};
    $html='<!doctype html><html><head><meta charset="utf-8"><title>Electronic Receipt '.$esc($receiptNumber).'</title><style>
    body{font-family:Arial,sans-serif;background:#f5f7fb;color:#172033;padding:30px}.receipt{max-width:720px;margin:auto;background:#fff;border:1px solid #dbe3ef;border-radius:16px;padding:30px;box-shadow:0 10px 30px rgba(15,35,70,.08)}h1{color:#0d3b78;margin:0 0 4px}.muted{color:#64748b}.total{font-size:26px;font-weight:800;color:#0d3b78}.row{display:flex;justify-content:space-between;border-bottom:1px solid #e7edf5;padding:11px 0}.paid{color:#15803d;font-weight:800}.footer{margin-top:25px;font-size:11px;color:#64748b}@media print{body{background:#fff;padding:0}.receipt{box-shadow:none;border:0}}
    </style></head><body><div class="receipt"><h1>BESTLINK COLLEGE</h1><div class="muted">Smart Payment Portal • Electronic Receipt</div>
    <div class="row"><b>Receipt No.</b><span>'.$esc($receiptNumber).'</span></div>
    <div class="row"><b>Payment Reference</b><span>'.$esc($p['reference_number']).'</span></div>
    <div class="row"><b>Student</b><span>'.$esc($p['full_name']).'</span></div>
    <div class="row"><b>Student ID</b><span>'.$esc($p['student_id']).'</span></div>
    <div class="row"><b>Fee</b><span>'.$esc($p['fee_name']).'</span></div>
    <div class="row"><b>Payment Period</b><span>'.$esc($p['installment_name'] ?? 'Not specified').'</span></div>
    <div class="row"><b>Payment Method</b><span>'.$esc($p['payment_method']).'</span></div>
    <div class="row"><b>Date</b><span>'.$esc($p['verified_at'] ?: $p['created_at']).'</span></div>
    <div class="row"><b>Status</b><span class="paid">PAID</span></div>
    <p class="total">₱'.number_format((float)$p['amount'],2).'</p>
    <div class="footer">This electronic receipt is system-generated and is stored with the payment record.</div></div>
    <script>window.onload=function(){setTimeout(function(){window.print()},250)}</script></body></html>';
    @file_put_contents($path,$html);
    $up=$pdo->prepare("UPDATE payments SET receipt_file_name=? WHERE id=?");
    $up->execute([$fileName,$paymentId]);
  }
  return $receiptNumber;
}


function ensure_fee_items_table(PDO $pdo): void {
  $pdo->exec("CREATE TABLE IF NOT EXISTS fee_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    percentage DECIMAL(6,3) NOT NULL DEFAULT 0,
    fixed_amount DECIMAL(12,2) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fee_name(name)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fee_items' AND COLUMN_NAME=?");
  $check->execute(['fixed_amount']);
  if(!(int)$check->fetchColumn()) $pdo->exec("ALTER TABLE fee_items ADD COLUMN fixed_amount DECIMAL(12,2) NULL AFTER percentage");

  // Capstone fee structure: no separate Tuition Fee. Existing historical rows are
  // preserved; only the active fee configuration is normalized for new billing.
  $pdo->exec("UPDATE fee_items SET active=0 WHERE name='Tuition Fee'");
  $defaults=[
    ['Miscellaneous Fees',0,4975.00],
    ['School RFID & Lace',0,350.00],
    ['Medical & Dental',0,400.00],
    ['Research Forum 2026',0,200.00],
    ['Student Handbook',0,250.00]
  ];
  $q=$pdo->prepare("INSERT INTO fee_items(name,percentage,fixed_amount,active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE percentage=VALUES(percentage),fixed_amount=VALUES(fixed_amount),active=1");
  foreach($defaults as $item) $q->execute($item);
}

function get_fee_items(PDO $pdo): array {
  ensure_payment_modules_tables($pdo);
  $assessment=(float)get_payment_setting($pdo,'default_assessment','6175');
  $dueDate=get_payment_setting($pdo,'due_date','');
  $q=$pdo->query("SELECT id,name,percentage,fixed_amount,active FROM fee_items WHERE active=1 ORDER BY id");
  $items=[];
  foreach($q->fetchAll() as $row){
    $amount = ($row['fixed_amount'] !== null && (float)$row['fixed_amount'] > 0)
      ? (float)$row['fixed_amount']
      : $assessment*((float)$row['percentage']/100);
    $items[]=[
      'id'=>(int)$row['id'],
      'name'=>(string)$row['name'],
      'percentage'=>(float)$row['percentage'],
      'fixed_amount'=>$row['fixed_amount']===null?null:(float)$row['fixed_amount'],
      'amount'=>round($amount,2),
      'due_date'=>$dueDate
    ];
  }
  return $items;
}

function get_payment_installments(PDO $pdo, int $userId = 0, ?array $summary = null): array {
  ensure_payment_modules_tables($pdo);
  $assessment = $summary ? (float)($summary['adjusted_assessment'] ?? $summary['total_assessment'] ?? 0) : (float)get_payment_setting($pdo,'default_assessment','50000');
  $q=$pdo->query("SELECT id,name,percentage,due_date,active,sort_order FROM payment_installments WHERE active=1 ORDER BY sort_order,id");
  $rows=$q->fetchAll();
  $payments=[];
  if($userId>0){
    $pq=$pdo->prepare("SELECT installment_id,
      COALESCE(SUM(CASE WHEN status IN ('Verified','Paid') THEN amount ELSE 0 END),0) AS verified_paid,
      COALESCE(SUM(CASE WHEN status IN ('Pending Verification','Pending Online Confirmation','Awaiting HelloMoney Payment') THEN amount ELSE 0 END),0) AS pending_paid
      FROM payments WHERE user_id=? AND installment_id IS NOT NULL GROUP BY installment_id");
    $pq->execute([$userId]);
    foreach($pq->fetchAll() as $r){ $payments[(int)$r['installment_id']]=['verified'=>(float)$r['verified_paid'],'pending'=>(float)$r['pending_paid']]; }
  }
  $out=[];
  foreach($rows as $r){
    $target=round($assessment*((float)$r['percentage']/100),2);
    $paid=min($target,(float)($payments[(int)$r['id']]['verified']??0));
    $pending=min(max(0,$target-$paid),(float)($payments[(int)$r['id']]['pending']??0));
    $balance=max(0,$target-$paid); $available=max(0,$balance-$pending);
    $due=(string)($r['due_date']??'');
    $today=date('Y-m-d');
    $status=$balance<=0?'Paid':($due!=='' && $due<$today?'Overdue':'Upcoming');
    $out[]=['id'=>(int)$r['id'],'name'=>(string)$r['name'],'percentage'=>(float)$r['percentage'],'due_date'=>$due,'target_amount'=>$target,'verified_paid'=>round($paid,2),'pending_amount'=>round($pending,2),'balance'=>round($balance,2),'available_to_pay'=>round($available,2),'status'=>$status,'sort_order'=>(int)$r['sort_order']];
  }
  return $out;
}

function get_student_fee_breakdown(PDO $pdo, int $userId, ?array $summary = null): array {
  ensure_payments_table($pdo);
  ensure_payment_modules_tables($pdo);
  if ($summary === null) $summary = get_student_financial_summary($pdo, $userId);
  $items = get_fee_items($pdo);
  $q = $pdo->prepare("SELECT fee_item_id,
      COALESCE(SUM(CASE WHEN status IN ('Verified','Paid') THEN amount ELSE 0 END),0) AS verified_paid,
      COALESCE(SUM(CASE WHEN status IN ('Pending Verification','Pending Online Confirmation') THEN amount ELSE 0 END),0) AS pending_paid
      FROM payments WHERE user_id=? AND fee_item_id IS NOT NULL GROUP BY fee_item_id");
  $q->execute([$userId]);
  $byId=[];
  foreach($q->fetchAll() as $r){ $byId[(int)$r['fee_item_id']]=['verified'=>(float)$r['verified_paid'],'pending'=>(float)$r['pending_paid']]; }
  foreach($items as &$item){
    $totalAssessment=(float)($summary['total_assessment']??0);
    $discountShare=$totalAssessment>0 ? (float)$summary['discount_amount']*((float)$item['amount']/$totalAssessment) : 0;
    $adjusted=max(0,(float)$item['amount']-$discountShare);
    $verified=min($adjusted,(float)($byId[(int)$item['id']]['verified']??0));
    $pending=min(max(0,$adjusted-$verified),(float)($byId[(int)$item['id']]['pending']??0));
    $balance=max(0,$adjusted-$verified);
    $available=max(0,$balance-$pending);
    $status=$balance<=0?'Paid':($item['due_date'] < date('Y-m-d')?'Overdue':'Open');
    $item['discount_amount']=round($discountShare,2);
    $item['adjusted_amount']=round($adjusted,2);
    $item['verified_paid']=round($verified,2);
    $item['pending_amount']=round($pending,2);
    $item['balance']=round($balance,2);
    $item['available_to_pay']=round($available,2);
    $item['status']=$status;
  }
  unset($item);
  return $items;
}

function get_student_financial_summary(PDO $pdo, int $userId): array {
  ensure_payments_table($pdo);
  ensure_payment_modules_tables($pdo);

  $feeItems=get_fee_items($pdo);
  $assessment=0.0; foreach($feeItems as $fi){$assessment+=(float)$fi['amount'];}
  $dueDate='';
  $penaltyPercent=0.0;

  $q=$pdo->prepare("SELECT COALESCE(SUM(discount_percent),0) FROM scholarship_applications WHERE user_id=? AND status='Approved'");
  $q->execute([$userId]);
  $discountPercent=min(100,(float)$q->fetchColumn());
  $discountAmount=$assessment*($discountPercent/100);
  $adjustedAssessment=max(0,$assessment-$discountAmount);

  $q=$pdo->prepare("SELECT
      COALESCE(SUM(CASE WHEN status IN ('Verified','Paid') THEN amount ELSE 0 END),0) AS verified_paid,
      COALESCE(SUM(CASE WHEN status IN ('Pending Verification','Pending Online Confirmation') THEN amount ELSE 0 END),0) AS pending_paid,
      COALESCE(SUM(CASE WHEN status='Rejected' THEN amount ELSE 0 END),0) AS rejected_paid
    FROM payments WHERE user_id=?");
  $q->execute([$userId]);
  $p=$q->fetch() ?: ['verified_paid'=>0,'pending_paid'=>0,'rejected_paid'=>0];

  $verifiedPaid=min($adjustedAssessment,(float)$p['verified_paid']);
  $baseBalance=max(0,$adjustedAssessment-$verifiedPaid);
  $today=new DateTimeImmutable('today');
  $due=null;
  try { $due=new DateTimeImmutable($dueDate); } catch(Throwable $e) { $due=null; }
  $isOverdue=false;
  $penaltyAmount=0.0;
  $outstanding=$baseBalance;
  $progress=$adjustedAssessment>0 ? min(100,($verifiedPaid/$adjustedAssessment)*100) : 100;

  $dueLabel='No due date set';
  $dueDetail='No payment deadline is currently configured.';
  $dueState='none';
  if($due){
    $dueFormatted=$due->format('F j, Y');
    if($baseBalance<=0){
      $dueLabel='Account Paid';
      $dueDetail='No outstanding balance is currently due.';
      $dueState='paid';
    } elseif($today>$due){
      $daysOverdue=(int)$due->diff($today)->format('%a');
      $dueLabel='Overdue';
      $dueDetail='Due since '.$dueFormatted.' ('.$daysOverdue.' '.($daysOverdue===1?'day':'days').' overdue).';
      $dueState='overdue';
    } else {
      $daysUntil=(int)$today->diff($due)->format('%a');
      $dueLabel=$dueFormatted;
      $dueDetail='Payment deadline is '.$dueFormatted.' ('.$daysUntil.' '.($daysUntil===1?'day':'days').' remaining).';
      $dueState='upcoming';
    }
  }

  $feeBreakdown=get_student_fee_breakdown($pdo,$userId, [
    'discount_amount'=>$discountAmount
  ]);

  return [
    'user_id'=>$userId,
    'total_assessment'=>round($assessment,2),
    'discount_percent'=>round($discountPercent,2),
    'discount_amount'=>round($discountAmount,2),
    'adjusted_assessment'=>round($adjustedAssessment,2),
    'verified_paid'=>round($verifiedPaid,2),
    'pending_paid'=>round((float)$p['pending_paid'],2),
    'rejected_paid'=>round((float)$p['rejected_paid'],2),
    'base_balance'=>round($baseBalance,2),
    'penalty_percent'=>round($penaltyPercent,2),
    'penalty_amount'=>round($penaltyAmount,2),
    'outstanding'=>round($outstanding,2),
    'progress'=>round($progress,2),
    'fee_breakdown'=>$feeBreakdown,
    'installments'=>get_payment_installments($pdo,$userId,[ 'adjusted_assessment'=>$adjustedAssessment ]),
    'due_date'=>$dueDate,
    'due_label'=>$dueLabel,
    'due_detail'=>$dueDetail,
    'due_state'=>$dueState,
    'is_overdue'=>(bool)$isOverdue
  ];
}

function require_admin(): array {
  $s=require_login();
  if(($s['role']??'user')!=='admin'){
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Admin access required.']); exit;
  }
  return $s;
}

function require_cashier(): array {
  $s=require_login();
  if(($s['role']??'user')!=='cashier'){
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Cashier access required.']); exit;
  }
  return $s;
}

function require_admin_or_cashier(): array {
  $s=require_login();
  if(!in_array(($s['role']??'user'), ['admin','cashier'], true)){
    http_response_code(403);
    echo json_encode(['success'=>false,'message'=>'Authorized staff access required.']); exit;
  }
  return $s;
}
