<?php
	require_once __DIR__ . '/dbconnection.class.php';
	require_once dirname(__DIR__) . '/inc/defaults.php';

	class session extends dbconnection{
		public function start_session($device_id,$token){
			error_reporting(1);
			$db_name = "";
			$conn = $this->connect("ccc_central");
			if (strpos($device_id, 'AA') !== false || strpos($device_id, 'IA') !== false){
				$user_result = $conn->query("SELECT `cd`.`db_name` FROM  user_device AS `ud` INNER JOIN `ccc_central`.`user` AS `u` ON `ud`.`user_id` = `u`.`id` INNER JOIN `ccc_central`.`client_db` AS `cd` ON `u`.`client_id` = `cd`.`id` WHERE `ud`.`device_id` = '{$device_id}' AND `ud`.`logout` = '0' AND `ud`.`access_token`='{$token}' ");

				$user_row = $user_result->fetch_assoc();

				$db_name = $user_row['db_name'];
			}
			return $db_name;
		}

        public function access($user_id,$module_id,$permission){
            if (!$this->check_session([])) return false;
            $auth=$GLOBALS['ccc_authenticated_context'];
            if ((int)$user_id !== (int)$auth['id']) return false;
            if ($auth['type']==='M') return false; // Staff capabilities never authorize member self-service.
            $conn=$this->connect('ccc_central');
            $stmt=$conn->prepare('SELECT up.id FROM `ccc_central`.user_permission up JOIN `ccc_central`.client_module cm ON cm.id=up.cm_id JOIN `ccc_central`.user u ON u.id=up.user_id WHERE up.user_id=? AND cm.client_id=? AND cm.module_id=? AND up.bid=? AND up.permission>=? AND cm.active=1 AND u.park=0 AND u.inactive=0 LIMIT 1');
            $uid=(int)$auth['id'];$cid=(int)$auth['client_id'];$mid=(int)$module_id;$bid=(int)$auth['bid'];$level=(int)$permission;
            $stmt->bind_param('iiiii',$uid,$cid,$mid,$bid,$level);$stmt->execute();$allowed=$stmt->get_result()->num_rows>0;$stmt->close();$conn->close();
            return $allowed;
        }

        public function access_app($user_id,$module_id,$permission,$bid){
            if (!$this->check_session([]) || (int)$bid !== (int)get_bid()) return false;
            return $this->access($user_id,$module_id,$permission);
        }

		public function can_edit_previous_month($user_id){
			if(empty($user_id))
				return false;
			$conn = $this->connect("ccc_central");
			if(!$conn || $conn->connect_errno)
				return false;
			$uid = (int)$user_id;
			$res = $conn->query("SELECT position_id, admin FROM `ccc_central`.`user` WHERE id = '{$uid}' AND park = '0' LIMIT 1");
			if($res && $res->num_rows > 0){
				$u = $res->fetch_assoc();
				$pos = (int)$u['position_id'];
				$isAdmin = (int)$u['admin'] === 1;
				$day = (int)date('d');
				$conn->close();
				return ($isAdmin || $pos === 10 || $pos === 6 || ($pos === 2 && $day === 1));
			}
			$conn->close();
			return false;
		}

		private function chat_bot_access($chat_id){
			$db_name = "";
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT `cd`.`db_name` FROM `ccc_central`.`client_db` AS cd INNER JOIN `ccc_central`.`user` AS u ON `cd`.`id` = `u`.`client_id` WHERE `u`.`chat_id` = '{$chat_id}' ")->fetch_assoc();

			$conn->close();
			return !empty($client) ? $client['db_name'] : $db_name;
		}

		private function moon_access($client_id) {
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT db_name FROM `ccc_central`.`client_db` WHERE id = '{$client_id}'")->fetch_assoc();
			$conn->close();
			return $client['db_name'];
		}

		private function iot_device_access($device_id) {
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT `cd`.`db_name` FROM `client_db` AS `cd` INNER JOIN `equipment_device_id` AS `edi` ON `cd`.`id` = `edi`.`client_id` WHERE `edi`.`device_id` = '{$device_id}'")->fetch_assoc();
			if (empty($client['db_name']) || !isset($client['db_name'])) { //Check for iot_bio
				$client = $conn->query("SELECT `cd`.`db_name` FROM `client_db` AS `cd` INNER JOIN `bio_device` AS `bd` ON `bd`.`client_id` = `cd`.`id` WHERE `bd`.`sr_no` = '{$device_id}'")->fetch_assoc();
			}
			$conn->close();
			return !empty($client) ? $client['db_name'] : "";
		}

		private function online_form_access($token) {
			$db_name = "";
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT `cd`.`db_name` FROM `ccc_central`.`client_db` AS cd INNER JOIN `online_inquiry` AS `oi` ON `cd`.`id` = `oi`.`client_id` WHERE `oi`.`form_token` = '{$token}'")->fetch_assoc();
			$conn->close();
			return !empty($client) ? $client['db_name'] : "";
		}

		private function webhook_access($token) {
			$db_name = "";
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT `cd`.`db_name` FROM `ccc_central`.`client_db` AS cd WHERE `cd`.`token` = '{$token}'")->fetch_assoc();
			// echo "SELECT `cd`.`db_name` FROM `ccc_central`.`client_db` AS cd WHERE `cd`.`token` = '{$token}'";
			$conn->close();
			return !empty($client) ? $client['db_name'] : "";
		}

		private function get_dbname ($client_id){
			$conn = $this->connect("ccc_central");
			$client = $conn->query("SELECT db_name FROM `ccc_central`.`client_db` WHERE id = '{$client_id}'")->fetch_assoc();
			$conn->close();
			return $client['db_name'];
		}

        public function check_session($request){
            $request = is_array($request) ? $request : [];
            if (!empty($GLOBALS['ccc_authenticated_context'])) return $GLOBALS['ccc_authenticated_context']['db_name'];
            if (session_status() === PHP_SESSION_NONE) {
                $sid = $request['session_id'] ?? ($_COOKIE[session_name()] ?? '');
                if (is_string($sid) && preg_match('/^[A-Za-z0-9,-]{16,128}$/D', $sid)) session_id($sid);
                if ($sid !== '') session_start();
            }
            $conn = $this->connect('ccc_central');
            $user = null;
            $uid = (int)($_SESSION['user_id'] ?? $_SESSION['uid'] ?? 0);
            if ($uid > 0 && !empty($_SESSION['db_name'])) {
                $stmt = $conn->prepare('SELECT u.id,u.client_id,u.contact_id,u.type,u.admin,u.position_id,u.auth_epoch,cd.db_name FROM `ccc_central`.`user` u JOIN `ccc_central`.`client_db` cd ON cd.id=u.client_id WHERE u.id=? AND u.park=0 AND u.inactive=0 AND cd.inactive=0');
                $stmt->bind_param('i', $uid); $stmt->execute(); $user=$stmt->get_result()->fetch_assoc(); $stmt->close();
                if (!$user || $user['db_name'] !== $_SESSION['db_name']) $user=null;
            } elseif (get_device() !== '' && get_token() !== '') {
                $device=get_device();$token=get_token();
                $stmt=$conn->prepare('SELECT u.id,u.client_id,u.contact_id,u.type,u.admin,u.position_id,u.auth_epoch,cd.db_name FROM `ccc_central`.`user_device` ud JOIN `ccc_central`.`user` u ON u.id=ud.user_id JOIN `ccc_central`.`client_db` cd ON cd.id=u.client_id WHERE ud.device_id=? AND ud.access_token=? AND ud.logout=0 AND ud.park=0 AND u.park=0 AND u.inactive=0 AND cd.inactive=0 LIMIT 1');
                $stmt->bind_param('ss',$device,$token);$stmt->execute();$user=$stmt->get_result()->fetch_assoc();$stmt->close();
            }
            if (!$user) { $conn->close(); return ''; }
            if (!empty($request['client_id']) && (int)$request['client_id'] !== (int)$user['client_id']) { $conn->close(); return ''; }
            $uid=(int)$user['id'];
            $stmt=$conn->prepare('SELECT bid FROM `ccc_central`.`user_bid` WHERE user_id=? UNION SELECT bid FROM `ccc_central`.`user_permission` WHERE user_id=? AND permission>0');
            $stmt->bind_param('ii',$uid,$uid);$stmt->execute();$bids=array_map('intval',array_column($stmt->get_result()->fetch_all(MYSQLI_ASSOC),'bid'));$stmt->close();
            if (!$bids && $user['type']!=='M') {
                $tenant=$this->connect($user['db_name']);$contact=(int)$user['contact_id'];
                $bids=array_map('intval',array_column($tenant->query('SELECT eb.bid FROM employee_bid eb JOIN employee e ON e.id=eb.employee_id WHERE e.contact_id='.$contact.' AND e.park=0 AND e.status=1')->fetch_all(MYSQLI_ASSOC),'bid'));$tenant->close();
            }
            if ((int)$user['admin'] === 1 && !empty($user['db_name'])) {
                $tenant=$this->connect($user['db_name']);
                $allBids=array_map('intval',array_column($tenant->query('SELECT id FROM branch WHERE park=0')->fetch_all(MYSQLI_ASSOC),'id'));$tenant->close();
                $bids=array_values(array_unique(array_merge($bids,$allBids)));
            }

            $requestedBid = 0;
            if (isset($_SERVER['HTTP_BID']) && (int)$_SERVER['HTTP_BID'] > 0) {
                $requestedBid = (int)$_SERVER['HTTP_BID'];
            } elseif (isset($request['bid']) && (int)$request['bid'] > 0) {
                $requestedBid = (int)$request['bid'];
            }

            if ($requestedBid > 0 && in_array($requestedBid, $bids, true)) {
                $bid = $requestedBid;
                $_SESSION['bid'] = $bid;
            } else {
                $bid = (int)($_SESSION['bid'] ?? ($requestedBid > 0 ? $requestedBid : 0));
            }
            // A member's self-service session remains identifiable even without staff grants.
            if ($bid<1 || (!in_array($bid,$bids,true) && $user['type']!=='M')) { $conn->close(); return ''; }

            $employeeId=0;
            $posId=(int)$user['position_id'];
            if(in_array($user['type'],['E','F'],true)){
                $tenant=$this->connect($user['db_name']);$contact=(int)$user['contact_id'];
                $employee=$tenant->query('SELECT id, position_id FROM employee WHERE contact_id='.$contact.' AND park=0 AND status=1 LIMIT 1')->fetch_assoc();$tenant->close();
                if(!$employee && !(int)$user['admin']) { $conn->close(); return ''; }
                $employeeId=(int)($employee['id']??0);
                if(!empty($employee['position_id'])){
                    $posId=(int)$employee['position_id'];
                }
            }

            // Universal Auto-Refresh for Active Sessions (All Positions):
            // When any employee's position, role template, or permissions change,
            // automatically refresh session credentials, position_id, and navigation without data loss.
            $needsRefresh = (
                (int)($_SESSION['auth_epoch'] ?? 0) !== (int)$user['auth_epoch'] ||
                (int)($_SESSION['position_id'] ?? 0) !== $posId ||
                (int)($_SESSION['admin'] ?? 0) !== (int)$user['admin']
            );

            if ($needsRefresh && !empty($_SESSION['db_name']) && in_array($user['type'], ['E', 'F'], true)) {
                require_once dirname(__DIR__) . '/controllers/userAuthentication.php';
                $userAuth = new userAuthentication();
                $_SESSION['permissions'] = $userAuth->permissions($conn, (int)$user['client_id'], $uid, $bid);
                $config = $userAuth->config($conn, (int)$user['client_id'], $uid, $bid, $user['type']);
                $_SESSION['config'] = $config;
            }

            $conn->close();

            $user['position_id']=$posId;
            $_SESSION['position_id']=$posId;
            $_SESSION['employee_id']=$employeeId;
            $GLOBALS['ccc_authenticated_context']=$user+['bid'=>$bid,'allowed_bids'=>$bids,'position_id'=>$posId];
            $GLOBALS['ccc_authenticated_context']['bid']=$bid;
            $_SESSION['user_id']=$uid;$_SESSION['client_id']=(int)$user['client_id'];
            $_SESSION['admin']=(int)$user['admin'];$_SESSION['user_type']=$user['type'];
            $_SESSION['db_name']=$user['db_name'];$_SESSION['bid']=$bid;
            $_SESSION['auth_epoch']=(int)$user['auth_epoch'];
            return $user['db_name'];
        }

		public function check_emp($user_id){
			$conn = $this->connect("ccc_central");
			$user = $conn->query("SELECT `contact_id`,`type` FROM `ccc_central`.`user` WHERE `type` = 'E' AND `id` = {$user_id};");

			$conn->close();

			return ($user->num_rows ? true : false);
	    }

		public function condition($condition, $value1){
			switch ($condition) {
				case 'check_emp':
					return $this->check_emp($value1);
					break;
				default:
					return false;
					break;
		    }
	    }
	}
?>
