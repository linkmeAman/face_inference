<?php
// Function to get the client IP address
function get_client_ip() {
    $ipaddress = '';
    if (isset($_SERVER['HTTP_CLIENT_IP']))
        $ipaddress = $_SERVER['HTTP_CLIENT_IP'];
    else if(isset($_SERVER['HTTP_X_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_X_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_X_FORWARDED'];
    else if(isset($_SERVER['HTTP_FORWARDED_FOR']))
        $ipaddress = $_SERVER['HTTP_FORWARDED_FOR'];
    else if(isset($_SERVER['HTTP_FORWARDED']))
        $ipaddress = $_SERVER['HTTP_FORWARDED'];
    else if(isset($_SERVER['REMOTE_ADDR']))
        $ipaddress = $_SERVER['REMOTE_ADDR'];
    else
        $ipaddress = '';
    return $ipaddress;
}
function get_device() {
	return $_SERVER['HTTP_DEVICE'] ?? '';
}
function get_token() {
    return $_SERVER['HTTP_TOKEN'] ?? '';
}
function get_uid() {
	return $GLOBALS['ccc_authenticated_context']['id'] ?? $_SESSION['user_id'] ?? $_SERVER['HTTP_UID'] ?? ($_SESSION['user_id'] ?? ($_SESSION['uid'] ?? ($GLOBALS['_POST']['uid'] ?? ($GLOBALS['_GET']['uid'] ?? 0))));
}
function get_device_name() {
	return $_SERVER['HTTP_USER_AGENT'] ?? '';
}
function get_bid() {
    return $GLOBALS['ccc_authenticated_context']['bid'] ?? $_SESSION['bid'] ?? $_SERVER['HTTP_BID'] ?? ($_SESSION['bid'] ?? ($GLOBALS['_POST']['bid'] ?? ($GLOBALS['_GET']['bid'] ?? 0)));
}

function set_branch_type(){
    session_start();
    $branch_type = "";
    foreach ($_SESSION["branch"] as $branch) {
        if ($branch["id"] === $_SESSION["bid"]) {
            $branch_type = $branch["type"];
            break;
        }
    }
    $_SESSION["branch_type"] = $branch_type;
    session_commit();
    return  $_SESSION["branch_type"];
}

function get_branch_type(){
    return  $_SESSION["branch_type"];
}

function getDatabase($conn) {
    //Fire sql for user db
    $sql_db_select = $conn->query("SELECT `cdb`.`db_name` FROM `client_db` `cdb`, `user` `u` WHERE `cdb`.`id` = `u`.`client_id` AND `cdb`.`inactive` = '0' AND `u`.`id` = '".get_uid()."'");
    if ($sql_db_select->num_rows) {
        $db_select = $sql_db_select->fetch_assoc();
        $db_select = $db_select['db_name'];
        //Connect to db
        $conn->select_db($db_select);
        return $conn;
    }
    else return null;
}
?>