<?php
require __DIR__.'/config.php';
require_admin();
ensure_user_academic_fields($pdo);

$in=json_decode(file_get_contents('php://input'),true) ?: [];
$action=(string)($in['action']??'');

try {
  if($action==='create'){
    $username=trim((string)($in['username']??''));
    $full=trim((string)($in['full_name']??''));
    $student=trim((string)($in['student_id']??''));
    $password=(string)($in['password']??'');
    $status=(string)($in['status']??'Active');
    $yearLevel=trim((string)($in['year_level']??''));
    if($yearLevel!=='' && !in_array($yearLevel,['1st Year','2nd Year','3rd Year','4th Year'],true)) $yearLevel='';

    if($username===''||$full===''||strlen($password)<8){
      http_response_code(422); echo json_encode(['success'=>false,'message'=>'Username, full name, and a password of at least 8 characters are required.']); exit;
    }
    if(!in_array($status,['Active','Inactive'],true)) $status='Active';

    $q=$pdo->prepare("SELECT id FROM users WHERE username=? LIMIT 1");
    $q->execute([$username]);
    if($q->fetch()){
      http_response_code(409); echo json_encode(['success'=>false,'message'=>'Username is already registered.']); exit;
    }

    $q=$pdo->prepare("INSERT INTO users(username,password_hash,full_name,student_id,year_level,role,status) VALUES(?,?,?,?,?, 'user', ?)");
    $q->execute([$username,password_hash($password,PASSWORD_DEFAULT),$full,$student,$yearLevel,$status]);
    $newId=(int)$pdo->lastInsertId();
    $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Created student account #'.$newId]);
    echo json_encode(['success'=>true,'message'=>'Student account created.','id'=>(int)$pdo->lastInsertId()]); exit;
  }

  $id=(int)($in['id']??0);
  if($id<1){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid student ID.']);exit;}

  if($action==='update'){
    $username=trim((string)($in['username']??''));
    $full=trim((string)($in['full_name']??''));
    $student=trim((string)($in['student_id']??''));
    $status=(string)($in['status']??'Active');
    $yearLevel=trim((string)($in['year_level']??''));
    if($yearLevel!=='' && !in_array($yearLevel,['1st Year','2nd Year','3rd Year','4th Year'],true)) $yearLevel='';

    if($username===''||$full===''||!in_array($status,['Active','Inactive'],true)){
      http_response_code(422);echo json_encode(['success'=>false,'message'=>'Invalid student information.']);exit;
    }

    $q=$pdo->prepare("SELECT id FROM users WHERE username=? AND id<>? LIMIT 1");
    $q->execute([$username,$id]);
    if($q->fetch()){http_response_code(409);echo json_encode(['success'=>false,'message'=>'Username is already used by another account.']);exit;}

    $q=$pdo->prepare("UPDATE users SET username=?,full_name=?,student_id=?,year_level=?,status=? WHERE id=? AND role='user'");
    $q->execute([$username,$full,$student,$yearLevel,$status,$id]);
    $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Updated student account #'.$id]);

    if(trim((string)($in['password']??''))!==''){
      $pw=(string)$in['password'];
      if(strlen($pw)<8){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters.']);exit;}
      $q=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='user'");
      $q->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
      $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Reset password for student #'.$id]);
    }
    echo json_encode(['success'=>true,'message'=>'Student account updated.']); exit;
  }

  if($action==='reset_password'){
    $pw=(string)($in['password']??'');
    if(strlen($pw)<8){http_response_code(422);echo json_encode(['success'=>false,'message'=>'Password must be at least 8 characters.']);exit;}
    $q=$pdo->prepare("UPDATE users SET password_hash=? WHERE id=? AND role='user'");
    $q->execute([password_hash($pw,PASSWORD_DEFAULT),$id]);
    echo json_encode(['success'=>true,'message'=>'Password reset successfully.']); exit;
  }

  if($action==='activate' || $action==='deactivate'){
    $status=$action==='activate'?'Active':'Inactive';
    $q=$pdo->prepare("UPDATE users SET status=? WHERE id=? AND role='user'");
    $q->execute([$status,$id]);
    $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Changed student #'.$id.' status to '.$status]);
    echo json_encode(['success'=>true,'message'=>'Account status updated.']); exit;
  }

  if($action==='delete'){
    $q=$pdo->prepare("SELECT id, username, full_name FROM users WHERE id=? AND role='user' LIMIT 1");
    $q->execute([$id]);
    $studentRow=$q->fetch();
    if(!$studentRow){
      http_response_code(404);
      echo json_encode(['success'=>false,'message'=>'Student account not found or cannot be deleted.']); exit;
    }

    $pdo->beginTransaction();
    try {
      // Remove the student's payment records first so the account can be deleted
      // cleanly without leaving orphaned payment rows in the verification center.
      $q=$pdo->prepare("DELETE FROM payments WHERE user_id=?");
      $q->execute([$id]);
      $q=$pdo->prepare("DELETE FROM scholarship_applications WHERE user_id=?");
      $q->execute([$id]);

      $q=$pdo->prepare("DELETE FROM users WHERE id=? AND role='user'");
      $q->execute([$id]);

      if($q->rowCount()!==1){
        throw new RuntimeException('Student account could not be deleted.');
      }

      $aq=$pdo->prepare("INSERT INTO audit_logs(user_id,action) VALUES(?,?)"); $aq->execute([(int)$_SESSION['user_id'],'Deleted student account #'.$id]);
      $pdo->commit();
      echo json_encode([
        'success'=>true,
        'message'=>'Student account and its payment records were deleted.',
        'id'=>$id,
        'username'=>$studentRow['username']
      ]); exit;
    } catch(Throwable $deleteError) {
      if($pdo->inTransaction()) $pdo->rollBack();
      throw $deleteError;
    }
  }

  http_response_code(400);
  echo json_encode(['success'=>false,'message'=>'Unknown admin action.']);
} catch(PDOException $e) {
  http_response_code(500);
  echo json_encode(['success'=>false,'message'=>'Database error while updating student account.']);
}
