<?php
require __DIR__.'/config.php';
header('Content-Type: text/plain; charset=utf-8');
try {
  ensure_payments_table($pdo);
  echo "PAYMENT BACKEND OK\n";
  echo "Database: bestlink_student_system\n";
  echo "Payments table: OK\n";
  echo "Logged in user: ".($_SESSION['username'] ?? 'NO')."\n";
  echo "Role: ".($_SESSION['role'] ?? 'NO')."\n";
} catch(Throwable $e) {
  http_response_code(500);
  echo "PAYMENT BACKEND ERROR\n".$e->getMessage();
}
