<?php
	header('Access-Control-Allow-Origin: *');
	header('Access-Control-Allow-Headers: Authorization, Content-Type,Authentication, X-Requested-With,uid,device,bid, token' );
	header('Access-Control-Allow-Methods:  POST, GET, OPTIONS, PUT, DELETE');

	//Browser gives out options request when cross origin api is called.
	if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
		http_response_code('200');
		die();
	}
 ?>