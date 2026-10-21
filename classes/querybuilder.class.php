<?php
require_once __DIR__."/services/ModuleFlowSql.php";
    error_reporting(0);
	class querybuilder {
		public function select($columns,$addQuote = 1,$distinct = 0){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return ModuleFlowSql::select($columns,$distinct);
			$query = ($distinct>0)?"SELECT DISTINCT ":"SELECT ";
			end($columns);         
			$last_key = key($columns);

			foreach ($columns as $key => $col) {
				if($key != $last_key)
					$query .= ($addQuote) ? "`".$col."`," : "".$col.",";
				else
					$query .= ($addQuote) ? "`".$col."`" : "".$col."";
			}

			return $query;
		}

		public function customSelect($columns,$alias,$distinct = 0){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return ModuleFlowSql::select($columns,$distinct,$alias);
			$query = ($distinct>0)?"SELECT DISTINCT ":"SELECT ";
			end($columns);         
			$last_key = key($columns);

			foreach ($columns as $key => $col) {
				if($key != $last_key)
					$query .= $alias.".".$col.",";
				else
					$query .= $alias.".".$col." ";
			}

			return $query;
		}

		public function where($clauses,$query){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return $query." WHERE ".ModuleFlowSql::clauses($clauses);
			$query.=" WHERE ";
			foreach ($clauses as $keyMain => $clause){
				if($keyMain > 0)
					$query .=" AND ";
				foreach ($clause as $keySub => $cond) {
					if($keySub == 0)
						$query.=" `".$cond."`";
					if($keySub == 1){
						switch ($cond) {
							case '=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '>':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '>=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<>':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '!=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'BETWEEN':
								$query.=" ".$cond." '".implode('\' AND \'',$clause[2])."'";
								break;

							case 'between':
								$query.=" ".$cond." '".implode('\' AND \'',$clause[2])."'";
								break;

							case 'IN':
								$query.=" ".$cond." ('".implode('\',\'',$clause[2])."')";
								break;

							case 'NOT IN':
								$query.=" ".$cond." ('".implode('\',\'',$clause[2])."')";
								break;

							case 'not in':
								$query.=" ".$cond." ('".implode('\',\'',$clause[2])."')";
								break;

							case 'in':
								$query.=" ".$cond." ('".implode('\',\'',$clause[2])."')";
								break;

							case 'LIKE':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'like':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'IS NULL':
								$query .= " ".$cond." ";
								break;

							case 'IS NOT NULL':
								$query .= " ".$cond." ";
								break;

							case 'NOW()':
								$query .= " <=".$cond." ";
								break;
								
							default:
								# code...
								break;
						}
						break;
					}

				}
				 
			}

			return $query;
		}

		public function customWhere($clauses,$query,$alias){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return $query." WHERE ".ModuleFlowSql::clauses($clauses,$alias);
			$query.=" WHERE ";
			foreach ($clauses as $keyMain => $clause){
				if($keyMain > 0)
					$query .=" AND ";
				foreach ($clause as $keySub => $cond) {
					if($keySub == 0)
						$query.=" ".$alias.".".$cond;
					if($keySub == 1){
						switch ($cond) {
							case '=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<>':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'BETWEEN':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'between':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'IN':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'NOT IN':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'not in':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'in':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'LIKE':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'like':
								$query.=" ".$cond." '".$clause[2]."'";
								break;
							
							default:
								# code...
								break;
						}
						break;
					}

				}
				 
			}

			return $query;
		}

		public function orWhere($clauses,$query){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return $query." AND (".ModuleFlowSql::clauses($clauses,'',' OR ').")";
			if(strpos($query,"WHERE") !== false)
				$query.=" AND (";
			else
				$query.=" WHERE (";

			foreach ($clauses as $keyMain => $clause){
				if($keyMain > 0)
					$query .=" OR ";
				foreach ($clause as $keySub => $cond) {
					if($keySub == 0)
						$query.=" ".$cond;
					if($keySub == 1){
						switch ($cond) {
							case '=':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<>':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '>':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case '<':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'BETWEEN':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'between':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;


							case 'IN':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'in':
								$custom_cond = $this->custom_query($clause[2]);
								$query.=" ".$cond." ".$custom_cond;
								break;

							case 'LIKE':
								$query.=" ".$cond." '".$clause[2]."'";
								break;

							case 'like':
								$query.=" ".$cond." '".$clause[2]."'";
								break;
							
							default:
								# code...
								break;
						}
						break;
					}

				}
				 
			}
			$query .= " )";
			return $query;
		}

		public function update($arr, $query, $conn = null){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return ModuleFlowSql::update($arr,$query);
			$query.=" ";
			end($arr);         
			$last_key = key($arr);
			foreach ($arr as $key => $obj) {
				if ($conn != null) {
					$obj['val'] = $conn->real_escape_string($obj['val']);
				}
				if($key != $last_key)
					$query .= '`'.$obj['col'].'` = "'.$obj['val'].'",';
				else
					$query .= '`'.$obj['col'].'` = "'.$obj['val'].'"';

			}
			$query .= ", modified_by = '".get_uid()."'";

			return $query;
		}

		public function order($orders,$query){
            if (class_exists("ModuleFlowRuntime",false) && ModuleFlowRuntime::active()) return ModuleFlowSql::order($orders,$query);
			$query .= " ORDER BY ";
			end($orders);         
			$last_key = key($orders);
			foreach ($orders as $keyMain => $first) {
				if($keyMain != $last_key){
			 		foreach ($first as $keySub => $val)
			 			$query.= $val." ";

			 		$query .= ",";
				}
				else
					foreach ($first as $keySub => $val)
			 			$query.= $val." ";

			} 
			return $query;
		}

	}


?>