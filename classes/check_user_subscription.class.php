<?php

	class check_user_subscription{

		public function get_date($conn,$contact_id,$code,$ext){
			if (empty($code) && !empty($contact_id)) {
				$mCode = $conn->query("SELECT `code` FROM `member` WHERE `contact_id` = '{$contact_id}' AND `status` = '1' AND `park` = '0' ORDER BY id DESC LIMIT 1")->fetch_assoc();
				if (!empty($mCode['code'])) {
					$code = $mCode['code'];
				}
			}
			$att_setting = $conn->query("SELECT `skip_session` FROM `attendance_settings` WHERE `bid` = (SELECT `bid` FROM `member` WHERE `code` = '{$code}' AND `contact_id` = '{$contact_id}')")->fetch_assoc();
			if (empty($att_setting['skip_session']) || !isset($att_setting['skip_session'])) {
				$att_setting['skip_session'] = 0;
			}
			$today = date('Y-m-d');
			$to_date = '';
			$payment = $conn->query("SELECT MIN(reminder) AS min_date FROM `invoice_payment_view` WHERE (contact_id = '{$contact_id}' AND `code` = '{$code}')  AND `clear` = '0' AND reminder <= '{$today}' AND invoice_park = '0' ")->fetch_assoc();
			if(!empty($payment['min_date'])){
				if(!empty($ext) && $ext > 0)
					$to_date= date('Y-m-d', strtotime("+".$ext."days", strtotime($payment['min_date'])));
				else
					$to_date= $payment['min_date'];
				// echo $to_date;
			}
			else{
				$invoice_item = $conn->query("SELECT end_date AS max_date,sessions,sessions_used FROM invoice_invoiceitem_view WHERE (contact_id = '{$contact_id}' AND `code` = '{$code}') AND end_date = (SELECT MAX(end_date) FROM invoice_invoiceitem_view WHERE (contact_id = '{$contact_id}' AND `code` = '{$code}') AND start_date <= '{$today}' and  end_date >= '{$today}' AND park = '0' AND end_date != '0000-00-00') ")->fetch_assoc();

				if(!empty($invoice_item['max_date'])){
					
					if($invoice_item['sessions'] == 0 || $att_setting['skip_session'] == 1){
						
						if(!empty($ext) && $ext > 0)
							$to_date= date('Y-m-d', strtotime("+".$ext."days", strtotime($invoice_item['max_date'])));
						else
							$to_date= $invoice_item['max_date'];
					}
					else if($invoice_item['sessions'] > 0){
						if($invoice_item['sessions_used'] < $invoice_item['sessions']){
							if(!empty($ext) && $ext > 0)
								$to_date= date('Y-m-d', strtotime("+".$ext."days", strtotime($invoice_item['max_date'])));
							else
								$to_date= $invoice_item['max_date'];
						}
					}
				}
				else{
					$member_ext = $conn->query("SELECT ext_type,count,type_rowid FROM member_extn WHERE (contact_id = '{$contact_id}' AND `code` = '{$code}') AND `park` = '0' ");
					if($member_ext->num_rows){
	
						while($row = $member_ext->fetch_assoc()){
							if($row['ext_type'] != $ext_type && $count != $row['count'] && $row['type_rowid'] != $rowid){
								if($row['ext_type'] == 0){
									$pay = $conn->query("SELECT reminder FROM invoice_payment_view WHERE id = '{$row['type_rowid']}' AND park = '0' AND clear = '0' AND invoice_park = '0' ");
									$pay_data = $pay->fetch_assoc();
									if(!empty($row['count']))
										$to_date = date('Y-m-d', strtotime("+".$row['count']."days", strtotime($pay_data['reminder'])));
								}
								else if($row['ext_type'] == 1){
									$item = $conn->query("SELECT `end_date` FROM invoice_invoiceitem_view WHERE item_id = '{$row['type_rowid']}' AND (`item_park` = '0' AND `park` = '0')");
									$ite_data = $item->fetch_assoc();
									if(!empty($row['count']))
										$to_date = date('Y-m-d', strtotime("+".$row['count']."days", strtotime($item_data['end_date'])));
								}
								else{
									$item = $conn->query("SELECT `end_date` FROM invoice_invoiceitem_view WHERE item_id = '{$row['type_rowid']}' AND (`item_park` = '0' AND `park` = '0')");
									$ite_data = $item->fetch_assoc();
									if(!empty($row['count']))
										$to_date = date('Y-m-d', strtotime("+".$row['count']."days", strtotime($item_data['end_date'])));
								}
								$ext_type = $row['ext_type'];
								$count = $row['count'];
								$rowid = $row['type_rowid']; 
							}
							else
								continue;
						}
					}						
				}
			}
			
			return $to_date;
		}
	}

 ?>