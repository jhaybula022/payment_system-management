<?php
require __DIR__.'/config.php'; require_admin();
ensure_user_academic_fields($pdo);
$q=$pdo->query("SELECT id,username,full_name,student_id,year_level,role,status,created_at FROM users WHERE role='user' ORDER BY id DESC");
echo json_encode(['success'=>true,'users'=>$q->fetchAll()]);
