<?php
// Add attendance-domain fixture tables to the existing temporary connection.
// Reuse the schema maintained by its real domain regression suite.
$source=file_get_contents(dirname(__DIR__).'/attendance/ATT-CORE-01-domain-service.php');
preg_match('/\$schema = <<<\x27SQL\x27\n(.*?)\nSQL;/s',$source,$match);
if(empty($match[1])) throw new RuntimeException('Domain fixture schema missing');
foreach(explode(";\n",$match[1]) as $sql){if(trim($sql)!=='')$db->query($sql);}
$db->query('ALTER TABLE batch ADD inactive INT DEFAULT 0, ADD start_date DATE, ADD end_date DATE');
$db->query('ALTER TABLE invoice_invoiceitem_view ADD bid INT DEFAULT 2, ADD quotation INT DEFAULT 0');
$sql=<<<'SQL'
CREATE TEMPORARY TABLE session(id INT PRIMARY KEY,batch_id INT,date DATE,start_time TIME,end_time TIME,park INT);
CREATE TEMPORARY TABLE batch_time(id INT PRIMARY KEY,batch_id INT,day_code INT,start_time TIME,end_time TIME,park INT NOT NULL DEFAULT 0);
CREATE TEMPORARY TABLE invoice_invoiceitem_module_view LIKE invoice_invoiceitem_view;
CREATE TEMPORARY TABLE attendance_scan(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scan_token CHAR(64),branch_id INT,batch_id INT,attendance_date DATE,session_id INT,event_type VARCHAR(32),actor_employee_id INT,roster_version CHAR(64),provider_id VARCHAR(100),provider_version VARCHAR(100),model_version_id INT,calibration_policy_version VARCHAR(100),status VARCHAR(20),expires_at DATETIME,completed_at DATETIME);
CREATE TEMPORARY TABLE attendance_scan_roster(id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,scan_id BIGINT UNSIGNED,member_id INT,candidate_ref VARCHAR(128),eligibility_status VARCHAR(20),consent_status VARCHAR(30),template_count INT,UNIQUE KEY once_member(scan_id,member_id));
SQL;
$db->multi_query($sql);while($db->more_results())$db->next_result();
$db->query("INSERT INTO batch(id,bid,venue_id,completed_sessions,batch,start_date,end_date) VALUES(11,2,501,3,'Browser fixture','2000-01-01','2100-01-01')");
$today=date('Y-m-d');$day=date('w');
$db->query("INSERT INTO session VALUES(7,11,'$today','10:00:00','11:00:00',0),(8,11,'$today','12:00:00','13:00:00',0)");
$db->query("INSERT INTO batch_time VALUES(1,11,$day,'10:00:00','13:00:00',0)");
$db->query("INSERT INTO invoice_item(id,sessions,sessions_used,start_date,end_date,batch_id) VALUES(21,10,0,'2000-01-01','2100-01-01',11),(22,0,0,'2000-01-01','2100-01-01',11)");
$db->query("INSERT INTO invoice_invoiceitem_view(item_id,contact_id,batch_id,start_date,end_date,sessions,sessions_used) VALUES(21,101,11,'2000-01-01','2100-01-01',10,0),(22,102,11,'2000-01-01','2100-01-01',0,0)");
$db->query('INSERT INTO invoice_invoiceitem_module_view SELECT * FROM invoice_invoiceitem_view');
