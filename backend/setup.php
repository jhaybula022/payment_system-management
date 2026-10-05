<?php
declare(strict_types=1);
$host = getenv('DB_HOST') ?: (getenv('DOCKER_ENV') ? 'db' : 'localhost');
$db = getenv('DB_DATABASE') ?: (getenv('DB_NAME') ?: 'bestlink_student_system');
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : ((getenv('DOCKER_ENV')) ? 'root' : '');
$port = (int)(getenv('DB_PORT') ?: 3306);
header('Content-Type:text/plain; charset=utf-8');
try {
  $pdo = new PDO("mysql:host=$host;port=$port;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
  $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
  $pdo->exec("USE `$db`");
  $pdo->exec("CREATE TABLE IF NOT EXISTS users(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    student_id VARCHAR(50),
    year_level VARCHAR(30) NULL,
    employee_id VARCHAR(60) NULL,
    role ENUM('admin','user','cashier') NOT NULL DEFAULT 'user',
    status ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $pdo->exec("CREATE TABLE IF NOT EXISTS payments(
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    fee_item_id INT UNSIGNED NULL,
    installment_id INT UNSIGNED NULL,
    amount DECIMAL(12,2) NOT NULL,
    payment_method VARCHAR(100) NOT NULL,
    payment_channel VARCHAR(30) NOT NULL DEFAULT 'Online',
    reference_number VARCHAR(100) NOT NULL,
    proof_file_name VARCHAR(255),
    status VARCHAR(30) NOT NULL DEFAULT 'Pending Verification',
    verified_by INT UNSIGNED NULL,
    verified_at DATETIME NULL,
    provider_payment_id VARCHAR(120) NULL,
    provider_status VARCHAR(60) NULL,
    payment_expires_at DATETIME NULL,
    receipt_number VARCHAR(120) NULL,
    receipt_file_name VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_pay_user (user_id), INDEX idx_pay_status(status), INDEX idx_pay_reference(reference_number)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

  $columns = [
    'user_id'=>"ALTER TABLE payments ADD COLUMN user_id INT UNSIGNED NOT NULL AFTER id",
    'fee_item_id'=>"ALTER TABLE payments ADD COLUMN fee_item_id INT UNSIGNED NULL AFTER user_id",
    'installment_id'=>"ALTER TABLE payments ADD COLUMN installment_id INT UNSIGNED NULL AFTER fee_item_id",
    'payment_channel'=>"ALTER TABLE payments ADD COLUMN payment_channel VARCHAR(30) NOT NULL DEFAULT 'Online' AFTER payment_method",
    'cashier_id'=>"ALTER TABLE payments ADD COLUMN cashier_id INT UNSIGNED NULL AFTER payment_channel",
    'processed_at'=>"ALTER TABLE payments ADD COLUMN processed_at DATETIME NULL AFTER cashier_id",
    'payment_date'=>"ALTER TABLE payments ADD COLUMN payment_date DATE NULL AFTER processed_at",
    'amount'=>"ALTER TABLE payments ADD COLUMN amount DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER user_id",
    'payment_method'=>"ALTER TABLE payments ADD COLUMN payment_method VARCHAR(100) NOT NULL DEFAULT '' AFTER amount",
    'reference_number'=>"ALTER TABLE payments ADD COLUMN reference_number VARCHAR(100) NOT NULL DEFAULT '' AFTER payment_method",
    'proof_file_name'=>"ALTER TABLE payments ADD COLUMN proof_file_name VARCHAR(255) NULL AFTER reference_number",
    'status'=>"ALTER TABLE payments ADD COLUMN status VARCHAR(30) NOT NULL DEFAULT 'Pending Verification' AFTER proof_file_name",
    'verified_by'=>"ALTER TABLE payments ADD COLUMN verified_by INT UNSIGNED NULL AFTER status",
    'verified_at'=>"ALTER TABLE payments ADD COLUMN verified_at DATETIME NULL AFTER verified_by",
    'provider_payment_id'=>"ALTER TABLE payments ADD COLUMN provider_payment_id VARCHAR(120) NULL AFTER verified_at",
    'provider_status'=>"ALTER TABLE payments ADD COLUMN provider_status VARCHAR(60) NULL AFTER provider_payment_id",
    'payment_expires_at'=>"ALTER TABLE payments ADD COLUMN payment_expires_at DATETIME NULL AFTER provider_status",
    'receipt_number'=>"ALTER TABLE payments ADD COLUMN receipt_number VARCHAR(120) NULL AFTER payment_expires_at",
    'receipt_file_name'=>"ALTER TABLE payments ADD COLUMN receipt_file_name VARCHAR(255) NULL AFTER receipt_number",
    'created_at'=>"ALTER TABLE payments ADD COLUMN created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER receipt_file_name"
  ];
  $check=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payments' AND COLUMN_NAME=?");
  foreach($columns as $name=>$sql){$check->execute([$name]);if(!(int)$check->fetchColumn())$pdo->exec($sql);}
  $pdo->exec("UPDATE payments SET payment_channel='Face-to-Face' WHERE LOWER(TRIM(payment_method))='cash' AND (payment_channel IS NULL OR payment_channel='' OR payment_channel='Online')");

  $checkYear=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='year_level'");
  $checkYear->execute(); if(!(int)$checkYear->fetchColumn()) $pdo->exec("ALTER TABLE users ADD COLUMN year_level VARCHAR(30) NULL AFTER student_id");
  $checkEmp=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users' AND COLUMN_NAME='employee_id'");
  $checkEmp->execute(); if(!(int)$checkEmp->fetchColumn()) $pdo->exec("ALTER TABLE users ADD COLUMN employee_id VARCHAR(60) NULL AFTER year_level");
  try { $pdo->exec("ALTER TABLE users MODIFY COLUMN role ENUM('admin','user','cashier') NOT NULL DEFAULT 'user'"); } catch(Throwable $e) {}

  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_installments (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(40) NOT NULL UNIQUE, percentage DECIMAL(6,3) NOT NULL DEFAULT 0, due_date DATE NULL, active TINYINT(1) NOT NULL DEFAULT 1, sort_order INT NOT NULL DEFAULT 1, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $insInstallment=$pdo->prepare("INSERT IGNORE INTO payment_installments(name,percentage,due_date,sort_order) VALUES(?,?,?,?)");
  foreach([['Prelim',30.000,'2026-09-30',1],['Midterm',30.000,'2026-11-15',2],['Finals',40.000,'2027-01-15',3]] as $it)$insInstallment->execute($it);
  $pdo->exec("CREATE TABLE IF NOT EXISTS payment_settings (setting_key VARCHAR(80) PRIMARY KEY, setting_value TEXT NULL, updated_by INT UNSIGNED NULL, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $pdo->exec("CREATE TABLE IF NOT EXISTS scholarship_applications (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, request_type VARCHAR(80) NOT NULL DEFAULT 'Discount Request', discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0, reason VARCHAR(255) NOT NULL, student_message VARCHAR(1000) NULL, status ENUM('For Review','Approved','Rejected') NOT NULL DEFAULT 'For Review', created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, reviewed_by INT UNSIGNED NULL, reviewed_at DATETIME NULL, INDEX idx_scholar_user(user_id), INDEX idx_scholar_status(status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $scholarCols=[
    'request_type'=>"ALTER TABLE scholarship_applications ADD COLUMN request_type VARCHAR(80) NOT NULL DEFAULT 'Discount Request' AFTER user_id",
    'student_message'=>"ALTER TABLE scholarship_applications ADD COLUMN student_message VARCHAR(1000) NULL AFTER reason",
    'proof_file_name'=>"ALTER TABLE scholarship_applications ADD COLUMN proof_file_name VARCHAR(255) NULL AFTER student_message",
    'proof_uploaded_at'=>"ALTER TABLE scholarship_applications ADD COLUMN proof_uploaded_at DATETIME NULL AFTER proof_file_name"
  ];
  $scholarCheck=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='scholarship_applications' AND COLUMN_NAME=?");
  foreach($scholarCols as $name=>$sql){$scholarCheck->execute([$name]);if(!(int)$scholarCheck->fetchColumn())$pdo->exec($sql);}
  $pdo->exec("CREATE TABLE IF NOT EXISTS fee_items (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,name VARCHAR(120) NOT NULL,percentage DECIMAL(6,3) NOT NULL DEFAULT 0,fixed_amount DECIMAL(12,2) NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_fee_name(name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $feeCheck=$pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='fee_items' AND COLUMN_NAME='fixed_amount'");$feeCheck->execute();if(!(int)$feeCheck->fetchColumn())$pdo->exec("ALTER TABLE fee_items ADD COLUMN fixed_amount DECIMAL(12,2) NULL AFTER percentage");
  $pdo->exec("UPDATE fee_items SET active=0 WHERE name='Tuition Fee'");
  $feeIns=$pdo->prepare("INSERT INTO fee_items(name,percentage,fixed_amount,active) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE percentage=VALUES(percentage),fixed_amount=VALUES(fixed_amount),active=1");
  foreach([['Miscellaneous Fees',0,4975],['School RFID & Lace',0,350],['Medical & Dental',0,400],['Research Forum 2026',0,200],['Student Handbook',0,250]] as $fee)$feeIns->execute($fee);
  $pdo->exec("CREATE TABLE IF NOT EXISTS audit_logs (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NULL, action VARCHAR(255) NOT NULL, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, INDEX idx_audit_created(created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
  $insSetting=$pdo->prepare("INSERT IGNORE INTO payment_settings(setting_key,setting_value) VALUES(?,?)");
  foreach([['default_assessment','6175'],['due_date',''],['penalty_percent','0'],['hellomoney_mode','demo'],['hellomoney_merchant_name','Bestlink College of the Philippines'],['hellomoney_qr_payload','']] as $st)$insSetting->execute($st);

  $checkUser=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
  $ins=$pdo->prepare("INSERT INTO users(username,password_hash,full_name,student_id,role,status) VALUES(?,?,?,?,?, 'Active')");
  $demo=[['admin','admin123','System Administrator',null,'admin'],['student','123456','Student User','BLCP-2026-0001','user']];
  foreach($demo as $u){$checkUser->execute([$u[0]]);if(!$checkUser->fetch())$ins->execute([$u[0],password_hash($u[1],PASSWORD_DEFAULT),$u[2],$u[3],$u[4]]);}
  echo "SETUP / DATABASE REPAIR COMPLETE\n\nAdmin: admin / admin123\nStudent: student / 123456\n\nExisting payment records were preserved.\nYou can now test payment submission and admin verification.\n\nFor security, delete setup.php after setup.\n";
} catch(Throwable $e) { http_response_code(500); echo "SETUP ERROR: ".$e->getMessage(); }
