<?php
declare(strict_types=1);
require __DIR__.'/config.php';
$s = require_login();
if (($s['role'] ?? 'user') !== 'user') { http_response_code(403); echo json_encode(['success'=>false,'message'=>'Student access required.']); exit; }
ensure_payment_modules_tables($pdo);
$type = trim((string)($_POST['request_type'] ?? ''));
$percent = (float)($_POST['discount_percent'] ?? 0);
$reason = trim((string)($_POST['reason'] ?? ''));
$details = trim((string)($_POST['student_message'] ?? ''));
$allowed = ['Academic Scholarship','Financial Assistance','Discount Request','Other'];
try {
  if (!in_array($type,$allowed,true)) throw new InvalidArgumentException('Please select a valid request type.');
  if ($percent <= 0 || $percent > 100) throw new InvalidArgumentException('Please enter a valid discount percentage.');
  if ($reason === '' || mb_strlen($reason)<5) throw new InvalidArgumentException('Please provide a short reason for your request.');
  if (mb_strlen($details)>1000) throw new InvalidArgumentException('Request details are too long.');
  if (!isset($_FILES['proof']) || (int)($_FILES['proof']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new InvalidArgumentException('Scholarship certificate/proof is required before a discount can be requested.');
  $file=$_FILES['proof'];
  if((int)$file['size']<=0 || (int)$file['size']>5*1024*1024) throw new InvalidArgumentException('Scholarship proof must be 5 MB or smaller.');
  $mime=''; if(class_exists('finfo')){$fi=new finfo(FILEINFO_MIME_TYPE);$mime=(string)$fi->file($file['tmp_name']);} if($mime==='')$mime=(string)($file['type']??'');
  $allowedM=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];
  if(!isset($allowedM[$mime])) throw new InvalidArgumentException('Scholarship proof must be JPG, PNG, WEBP, or PDF.');
  $q=$pdo->prepare("SELECT id FROM scholarship_applications WHERE user_id=? AND request_type=? AND status='For Review' LIMIT 1");$q->execute([(int)$s['user_id'],$type]);
  if($q->fetch()) throw new InvalidArgumentException('You already have a pending request of this type. Please wait for review.');
  $dir=__DIR__.'/uploads/scholarship_proofs'; if(!is_dir($dir))@mkdir($dir,0755,true); if(!is_writable($dir))throw new RuntimeException('Scholarship proof folder is not writable.');
  $stored=bin2hex(random_bytes(16)).'.'.$allowedM[$mime]; if(!move_uploaded_file($file['tmp_name'],$dir.'/'.$stored))throw new RuntimeException('Unable to save scholarship proof.');
  try{
    $q=$pdo->prepare("INSERT INTO scholarship_applications(user_id,request_type,discount_percent,reason,student_message,proof_file_name,proof_uploaded_at,status) VALUES(?,?,?,?,?,?,NOW(),'For Review')");
    $q->execute([(int)$s['user_id'],$type,$percent,$reason,$details,$stored]);
  }catch(Throwable $db){@unlink($dir.'/'.$stored);throw $db;}
  echo json_encode(['success'=>true,'message'=>'Scholarship request with proof submitted for Admin review.','id'=>(int)$pdo->lastInsertId()]);
}catch(InvalidArgumentException $e){http_response_code(422);echo json_encode(['success'=>false,'message'=>$e->getMessage()]);}
catch(Throwable $e){error_log('Scholarship request error: '.$e->getMessage());http_response_code(500);echo json_encode(['success'=>false,'message'=>'Unable to submit your scholarship request right now.']);}
