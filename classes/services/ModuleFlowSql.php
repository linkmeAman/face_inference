<?php
/** Strict SQL compatibility adapter for legacy structured payloads. */
final class ModuleFlowSql
{
    public static function identifier($name): string
    {
        if(!is_string($name)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z_][A-Za-z0-9_]*)?$/D',$name)) throw new InvalidArgumentException('Unsupported database field');
        return implode('.',array_map(fn($part)=>'`'.$part.'`',explode('.',$name)));
    }
    public static function value($value): string
    {
        if($value===null)return 'NULL';
        if(!is_scalar($value))throw new InvalidArgumentException('Expected a scalar field value');
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');
        return "'".$db->real_escape_string((string)$value)."'";
    }
    public static function select($cols,$distinct=0,$alias=''): string
    {
        if(!is_array($cols)||!$cols)throw new InvalidArgumentException('Select approved fields explicitly');
        return 'SELECT '.($distinct?'DISTINCT ':'').implode(',',array_map(fn($col)=>self::identifier($alias!==''?$alias.'.'.$col:$col),$cols)).' ';
    }
    public static function clauses($clauses,$alias='',string $join=' AND '): string
    {
        if(!is_array($clauses)||!$clauses)throw new InvalidArgumentException('Empty filters are not allowed');
        $parts=[];
        foreach($clauses as $c){
            if(!is_array($c)||count($c)<2)throw new InvalidArgumentException('Invalid filter');
            $col=self::identifier(($alias!==''?$alias.'.':'').$c[0]);$op=strtoupper(trim((string)$c[1]));$v=$c[2]??null;
            if(in_array($op,['IS NULL','IS NOT NULL'],true)){$parts[]="$col $op";continue;}
            if(in_array($op,['IN','NOT IN'],true)){
                if(!is_array($v))throw new InvalidArgumentException('IN requires a list');
                $parts[]=$v?"$col $op (".implode(',',array_map([self::class,'value'],$v)).')':($op==='IN'?'0=1':'1=1');continue;
            }
            if($op==='BETWEEN'){
                if(!is_array($v)||count($v)!==2)throw new InvalidArgumentException('BETWEEN requires two values');
                $parts[]="$col BETWEEN ".self::value($v[0]).' AND '.self::value($v[1]);continue;
            }
            if(!in_array($op,['=','!=','<>','<','>','<=','>=','LIKE','NOT LIKE'],true))throw new InvalidArgumentException('Unsupported filter operator');
            $parts[]="$col $op ".self::value($v);
        }
        return implode($join,$parts);
    }
    public static function update($arr,string $query): string
    {
        if(!is_array($arr)||!$arr)throw new InvalidArgumentException('No changes supplied');
        $parts=[];
        foreach($arr as $v){
            $col=$v['col']??'';
            if(in_array($col,['id','created_by','created_at','client_id'],true))throw new InvalidArgumentException('Immutable field: '.$col);
            if($col==='modified_by')continue;
            $parts[]=self::identifier($col).'='.self::value($v['val']??null);
        }
        $parts[]='modified_by='.(int)get_uid();
        return $query.' '.implode(',',$parts);
    }
    public static function order($orders,string $query): string
    {
        if(!is_array($orders)||!$orders)return $query;
        $parts=[];foreach($orders as $o){$dir=strtoupper($o[1]??'ASC');if(!in_array($dir,['ASC','DESC'],true))throw new InvalidArgumentException('Invalid sort direction');$parts[]=self::identifier($o[0]).' '.$dir;}
        return $query.' ORDER BY '.implode(',',$parts);
    }
}
