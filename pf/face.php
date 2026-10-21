<?php
// Preserve old bookmarks; everyday camera UI lives inside attendance.php.
$params=[];
foreach(['mode','contact_id','batch_id','date','attendance_session_id','time'] as $key)if(isset($_GET[$key]) && is_string($_GET[$key]))$params[$key]=$_GET[$key];
header('Cache-Control: no-store');
header('Location: /pf/attendance.php'.($params?'?'.http_build_query($params):''));
exit;
