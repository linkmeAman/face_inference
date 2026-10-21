<?php
	error_reporting(1);
	session_start();

	// header("location:/".getRequestUrl());
	if(array_key_exists('config', $_SESSION) && array_key_exists('db_name', $_SESSION)){
	    if ((!isset($_SESSION['db_name']) && empty($_SESSION['db_name'])) && (!isset($_SESSION['config']) && empty($_SESSION['config']))){
	        session_start(); 
	        session_destroy(); 
	        header("location:/".getRequestUrl());
	    }
		else if(!empty($_COOKIE['inst_name'])){
			$_SESSION['inst_logo'] = $_COOKIE['inst_logo'];
	    	clear_cookies();
	    }
	    else if (!empty($_COOKIE['inst_logo'])) {
	    	$_SESSION['inst_logo'] = $_COOKIE['inst_logo'];
	    	clear_cookies();
		}
		else if (!empty($_COOKIE['firstLogin'])) {
	    	$_SESSION['firstLogin'] = 0;
	    	clear_cookies();
	    }
	}
	else{
	    session_start(); 
	    session_destroy(); 
	    header("location:/".getRequestUrl()); 
	}


	function clear_cookies(){
		if (isset($_SERVER['HTTP_COOKIE'])) {//do we have any
		    $cookies = explode(';', $_SERVER['HTTP_COOKIE']);//get all cookies 
		    foreach($cookies as $cookie) {//loop
		        $parts = explode('=', $cookie);//get the bits we need
		        $name = trim($parts[0]);
		        setcookie($name, '', time()-1000);//kill it
		        setcookie($name, '', time()-1000, '/');//kill it more
		    }
		}
	}

	function getRequestUrl() {
		$url = "signin.html";
		$url .= "?link=" . explode("?", $_SERVER['REQUEST_URI'])[0];
		foreach ($_GET as $key => $value) {
			$url .= "&".$key."=".$value;
		}
		return $url;
	}

?>
