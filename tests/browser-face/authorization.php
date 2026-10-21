<?php
require_once dirname(__DIR__,2).'/controllers/browserFace.php';
$operator=['db_name'=>'ccc_database_test','user_id'=>1,'bid'=>2,'face_csrf'=>'test-csrf'];
$server=['HTTPS'=>'on','HTTP_HOST'=>'ccc.example','HTTP_ORIGIN'=>'https://ccc.example','HTTP_X_FACE_CSRF'=>'test-csrf'];
browserFace::validateBrowserRequest($server,$operator,'POST');
$cases=[[$server,[], 'GET'],[array_merge($server,['HTTPS'=>'off']),$operator,'GET'],
    [array_merge($server,['HTTP_X_FACE_CSRF'=>'bad']),$operator,'POST'],
    [array_merge($server,['HTTP_ORIGIN'=>'https://evil.example']),$operator,'POST'],
    [array_merge($server,['HTTP_UID'=>'999']),$operator,'GET']];
foreach($cases as $i=>$case){try{browserFace::validateBrowserRequest(...$case);}catch(InvalidArgumentException $error){echo 'PASS authorization rejection '.($i+1).PHP_EOL;continue;}throw new RuntimeException('Invalid request accepted');}
echo 'PASS valid authenticated same-origin request'.PHP_EOL;
