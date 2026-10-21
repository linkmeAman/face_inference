<?php
// Actual legacy portrait controller, existing central permissions (read only),
// connection-scoped temporary member/portrait tables and invented image bytes.
// No real portrait, consent, user or production record is changed.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once dirname(__DIR__,2).'/controllers/faceEnrollment.php';
if(($argv[1] ?? '')!=='--case'){
    foreach(['authorized','cross-branch','spoofed-actor','spoofed-tenant','missing-member'] as $case){
        $process=proc_open([PHP_BINARY,__FILE__,'--case',$case],[1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);foreach($pipes as $pipe)fclose($pipe);$exit=proc_close($process);
        $expected=in_array($case,['authorized','spoofed-tenant'],true)?200:403;
        if($exit!==0 || !str_ends_with($out,"STATUS:$expected\n") || $error!=='')throw new RuntimeException('Portrait access failed: '.$case.' '.$error.' '.$out);
        if($expected===200 && $out!=="fixture-portrait-only\nSTATUS:200\n")throw new RuntimeException('Unexpected portrait body');
        if($expected===403 && $out!=="\nSTATUS:403\n")throw new RuntimeException('Unauthorized portrait body');
        echo 'PASS existing portrait access '.$case.PHP_EOL;
    }
    exit;
}
$case=$argv[2];$connector=new dbconnection;$central=$connector->connect('ccc_central');
$access="EXISTS (SELECT 1 FROM client_module cm JOIN module m ON m.id=cm.module_id JOIN user_permission up ON up.cm_id=cm.id AND up.user_id=u.id WHERE cm.client_id=u.client_id AND m.module IN ('Member','Attendance') AND m.park=0 AND cm.active=1 AND up.bid=2 AND up.permission>=1)";
$allowed=$central->query('SELECT u.id FROM user u WHERE '.$access.' LIMIT 1')->fetch_assoc();
$denied=$central->query('SELECT u.id FROM user u WHERE NOT '.$access.' LIMIT 1')->fetch_assoc();$central->close();
if(!$allowed || !$denied)throw new RuntimeException('Existing central permission fixtures unavailable');
$db=$connector->connect('ccc_database_test');
if($db->query('SELECT DATABASE()')->fetch_row()[0]!=='ccc_database_test')throw new RuntimeException('Temporary test connection required');
$db->query('CREATE TEMPORARY TABLE member(id INT PRIMARY KEY,contact_id INT,bid INT)');
$db->query('CREATE TEMPORARY TABLE biometric_subject(id INT PRIMARY KEY,contact_id INT)');
$db->query('CREATE TEMPORARY TABLE face_identity_reference(id INT PRIMARY KEY,subject_id INT,status VARCHAR(20),verified_at DATETIME,encrypted_portrait BLOB)');
$db->query('INSERT INTO member VALUES(1,10,2),(2,20,4)');$db->query('INSERT INTO biometric_subject VALUES(1,10),(2,20),(3,99)');
putenv('FACE_EMBEDDING_KEY=public-portrait-authorization-test-only');putenv('FACE_EMBEDDING_KEY_ID=portrait-test-only');
$cipher=new FaceEmbeddingCipher('public-portrait-authorization-test-only','portrait-test-only');$bytes=$cipher->encryptBytes('fixture-portrait-only');
$s=$db->prepare("INSERT INTO face_identity_reference VALUES(1,1,'ACTIVE',NOW(),?),(2,2,'ACTIVE',NOW(),?),(3,3,'ACTIVE',NOW(),?)");$s->bind_param('sss',$bytes,$bytes,$bytes);$s->execute();
$_SESSION=['db_name'=>'ccc_database_test','bid'=>2,'user_id'=>(int)($case==='spoofed-actor'?$denied['id']:$allowed['id'])];
$_SERVER=['HTTPS'=>'on'];$request=['contact_id'=>10];
if($case==='cross-branch')$request=['contact_id'=>20,'branch_id'=>4];
if($case==='spoofed-actor')$request['actor_employee_id']=(int)$allowed['id'];
if($case==='spoofed-tenant')$request['client_id']=2147483647;
if($case==='missing-member')$request=['contact_id'=>99];
class PortraitTemporaryController extends faceEnrollment{
    public function __construct(private mysqli $fixture){}
    public function connect($name){return $name==='ccc_database_test'?$this->fixture:parent::connect($name);}
}
register_shutdown_function(fn()=>print("\nSTATUS:".http_response_code()."\n"));
(new PortraitTemporaryController($db))->streamReferencePortrait($request);
