<?php
// CLI provisioning only: copies explicitly designated zero-value test records.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__).'/classes/dbconnection.class.php';
require_once dirname(__DIR__).'/classes/services/BrowserFacePilot.php';
require_once dirname(__DIR__).'/classes/services/BrowserFaceService.php';

function provisionFacePilot(mysqli $admin,array $config):void
{
    BrowserFacePilot::scope($config,'ccc_database',2);$name=$config['database'];$source='ccc_database';
    $admin->select_db($source);
    if((int)$admin->query("SELECT COUNT(*) FROM member m JOIN contact c ON c.id=m.contact_id WHERE m.bid=2 AND m.status=1 AND m.park=0 AND m.id IN(623,624,625) AND c.id IN(791,792,793) AND CONCAT_WS(' ',c.fname,c.lname) IN('Face Camera Test One','Face Camera Test Two','Face Camera Test Three')")->fetch_row()[0]!==3)throw new RuntimeException('Test identity fingerprint changed');
    if((int)$admin->query('SELECT COUNT(*) FROM invoice_item WHERE id IN(1188,1189,1190) AND batch_id=33 AND item_amount=0 AND item_total=0')->fetch_row()[0]!==3
        || (int)$admin->query('SELECT COUNT(*) FROM batch WHERE id=33 AND bid=2')->fetch_row()[0]!==1)throw new RuntimeException('Test membership scope changed');
    $admin->query("CREATE DATABASE `$name`"); // Never reuse or erase an existing DB.
    $admin->query("GRANT ALL ON `$name`.* TO 'nahid'@'localhost'");
    $admin->select_db($name);
    $tables=['member','contact','batch','batch_time','session','invoice_item','attendance','attendance_event','attendance_settings','attendance_scan','attendance_scan_roster','biometric_consent','face_template','face_model_version','face_web_request','face_web_calibration','face_web_migration'];
    foreach($tables as $table)$admin->query("CREATE TABLE `$table` LIKE `$source`.`$table`");
    foreach(['member'=>'id IN(623,624,625)','contact'=>'id IN(791,792,793)','batch'=>'id=33','batch_time'=>'batch_id=33','session'=>'batch_id=33','invoice_item'=>'id IN(1188,1189,1190)','attendance_settings'=>'bid=2'] as $table=>$where)$admin->query("INSERT INTO `$table` SELECT * FROM `$source`.`$table` WHERE $where");
    // Pilot starts at zero usage. Only copied test memberships can be deducted.
    $admin->query('UPDATE invoice_item SET sessions_used=0');
    $contract=BrowserFacePolicy::contract();$s=$admin->prepare("INSERT INTO face_model_version SELECT * FROM `$source`.face_model_version WHERE model_name=? AND preprocessing_version=? AND model_checksum=?");$s->bind_param('sss',$contract['model_version'],$contract['preprocessing_version'],$contract['recognizer_source_sha256']);$s->execute();
    if($s->affected_rows!==1)throw new RuntimeException('Current model registry required');
    $admin->multi_query(file_get_contents(dirname(__DIR__).'/database/migrations/20261009_03_browser_face_lifecycle.sql'));while($admin->more_results())$admin->next_result();
    facePilotMembership($admin,$name);
    $admin->query('CREATE TABLE face_pilot_owner(token_hash CHAR(64) NOT NULL,source_database VARCHAR(100) NOT NULL,branch_id INT NOT NULL,batch_id INT NOT NULL,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)');
    $hash=hash('sha256',$config['owner_token']);$s=$admin->prepare("INSERT INTO face_pilot_owner(token_hash,source_database,branch_id,batch_id) VALUES(?,'ccc_database',2,33)");$s->bind_param('s',$hash);$s->execute();
    BrowserFacePilot::verify($admin,$config);facePilotSessionDays($admin,$config);
}

function facePilotSessionDays(mysqli $db,array $config):void
{
    BrowserFacePilot::verify($db,$config);
    $base=$db->query('SELECT id FROM session WHERE batch_id=33 AND park=0 ORDER BY id LIMIT 1')->fetch_assoc();if(!$base)throw new RuntimeException('Existing test session required');
    $columns=array_column($db->query('SHOW COLUMNS FROM session')->fetch_all(MYSQLI_ASSOC),'Field');$columns=array_values(array_diff($columns,['id']));
    for($day=0;$day<7;$day++){
        $date=date('Y-m-d',strtotime('+'.$day.' day'));$s=$db->prepare('SELECT id FROM session WHERE batch_id=33 AND date=? AND park=0');$s->bind_param('s',$date);$s->execute();if($s->get_result()->num_rows)continue;
        $fields=array_map(fn($column)=>$column==='date'?'?':"`$column`",$columns);
        $s=$db->prepare('INSERT INTO session(`'.implode('`,`',$columns).'`) SELECT '.implode(',',$fields).' FROM session WHERE id=? AND batch_id=33');$id=(int)$base['id'];$s->bind_param('si',$date,$id);$s->execute();
    }
}


function facePilotMembership(mysqli $admin,string $name):void
{
    $source='ccc_database';
    foreach(['invoice_invoiceitem_view','invoice_invoiceitem_module_view'] as $view){
        // The original display-only test invoices use employee_id=0, which
        // the production invoice view excludes via its counsellor INNER JOIN.
        // The pilot's explicit test membership seed comes from the existing
        // module roster. All domain validity/deduction checks remain intact.
        $seed=$view.'_seed';$admin->query("CREATE TABLE `$seed` AS SELECT * FROM `$source`.`invoice_invoiceitem_module_view` WHERE item_id IN(1188,1189,1190) AND contact_id IN(791,792,793) AND batch_id=33");
        $columns=array_column($admin->query("SHOW COLUMNS FROM `$seed`")->fetch_all(MYSQLI_ASSOC),'Field');
        $fields=array_map(fn($column)=>in_array($column,['sessions_used','sessions'],true)?"i.`$column` AS `$column`":"s.`$column`",$columns);
        $admin->query("CREATE SQL SECURITY INVOKER VIEW `$view` AS SELECT ".implode(',',$fields)." FROM `$name`.`$seed` s JOIN `$name`.invoice_item i ON i.id=s.item_id");
    }
}

function facePilotMain(array $argv):void
{
    $command=$argv[1] ?? '';$path=$argv[2] ?? '';
    if(!in_array($command,['create','verify','update-report','refresh-membership','session-days'],true) || !$path)throw new InvalidArgumentException('Usage: php scripts/browser_face_pilot.php create|verify|update-report|session-days /private/pilot.json [private-report.json]');
    $config=BrowserFacePilot::load('ccc_database',2,$path);
    mysqli_report(MYSQLI_REPORT_ERROR|MYSQLI_REPORT_STRICT);
    if($command==='create' || $command==='refresh-membership'){$db=new mysqli('localhost',getenv('FACE_TEST_ADMIN_USER') ?: 'root',getenv('FACE_TEST_ADMIN_PASSWORD') ?: '');if($command==='create')provisionFacePilot($db,$config);else{$db->select_db($config['database']);BrowserFacePilot::verify($db,$config);}}else{$db=(new dbconnection)->connect($config['database']);BrowserFacePilot::verify($db,$config);}
    if($command==='refresh-membership'){
        foreach(['face_template','face_web_calibration','attendance','attendance_event'] as $table)if((int)$db->query("SELECT COUNT(*) FROM `$table`")->fetch_row()[0])throw new RuntimeException('Pilot must be unused before refreshing its test membership seed');
        foreach(['invoice_invoiceitem_view','invoice_invoiceitem_module_view'] as $view){$db->query("DROP VIEW `$view`");$db->query("DROP TABLE `".$view."_seed`");}
        facePilotMembership($db,$config['database']);BrowserFacePilot::verify($db,$config);
    }
    if($command==='session-days')facePilotSessionDays($db,$config);
    if($command==='update-report'){
        $report=realpath($argv[3] ?? '');$root=realpath(dirname(__DIR__));if(!$report || strpos($report,$root.DIRECTORY_SEPARATOR)===0 || !is_readable($report))throw new InvalidArgumentException('Private report outside webroot required');
        $config['report_path']=$report;$config['report_sha256']=hash_file('sha256',$report);$policy=BrowserFacePilot::policy($config);
        if(!$policy['ready'])throw new RuntimeException('Pilot report lacks compatible evidence or explicit isolated-demo authorization.');
        BrowserFacePilot::verify($db,$config);
        $temporary=$path.'.'.bin2hex(random_bytes(5));$old=umask(0077);try{file_put_contents($temporary,json_encode($config,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));chmod($temporary,fileperms($path)&0777);chgrp($temporary,filegroup($path));if(!rename($temporary,$path))throw new RuntimeException('Pilot configuration update failed');}finally{umask($old);if(is_file($temporary))unlink($temporary);}
    }
    $policy=BrowserFacePilot::policy($config);echo json_encode(['isolated'=>true,'database'=>$config['database'],'branch_id'=>2,'batch_id'=>33,'test_members'=>[623,624,625],'matching_ready'=>$policy['ready'],'production_matching_changed'=>false])."\n";
}
if(realpath($_SERVER['SCRIPT_FILENAME'] ?? '')===__FILE__)try{facePilotMain($argv);}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
