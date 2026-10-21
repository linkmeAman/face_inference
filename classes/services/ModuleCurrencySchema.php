<?php
/** Preserve currency fractions in the database as well as in request validation. */
final class ModuleCurrencySchema
{
    private const FIELDS=[
        'service'=>['amount','tax_amount','total','secondary_amount','secondary_tax_amount','secondary_total','usd_amount','demo_discount_total'],
        'invoice'=>['amount','tax_amount','total','balance'],
        'invoice_item'=>['item_amount','item_discount','item_tax_amount','item_total'],
        'payment'=>['amount','balance','addpay_amount'],
    ];
    private const RATES=['service'=>['tax','secondary_tax','demo_discount'],'invoice'=>['tax'],'invoice_item'=>['item_tax'],'payment'=>['addpay_percent']];
    public static function plan(mysqli $db): array
    {
        $plan=[];
        foreach(self::FIELDS as $table=>$fields){
            foreach($db->query('SHOW FULL COLUMNS FROM `'.$table.'`')->fetch_all(MYSQLI_ASSOC) as $c){
                $col=$c['Field'];$rate=in_array($col,self::RATES[$table]??[],true);
                if(!$rate&&!in_array($col,$fields,true))continue;
                $scale=$rate?4:2;
                if(preg_match('/^decimal\((\d+),(\d+)\)$/i',$c['Type'],$m)&&(int)$m[2]>=$scale)continue;
                if(str_contains($c['Extra'],'GENERATED'))throw new RuntimeException('Review generated currency column '.$table.'.'.$col);
                // Refuse to round historical fractions as part of a schema repair.
                if((int)$db->query("SELECT COUNT(*) FROM `$table` WHERE `$col`<>ROUND(`$col`,$scale)")->fetch_row()[0])throw new RuntimeException('Historical precision needs review for '.$table.'.'.$col);
                $definition='`'.$col.'` DECIMAL(15,'.$scale.') '.($c['Null']==='YES'?'NULL':'NOT NULL');
                if($c['Default']!==null)$definition.=" DEFAULT '".$db->real_escape_string((string)$c['Default'])."'";
                elseif($c['Null']==='YES')$definition.=' DEFAULT NULL';
                if($c['Comment']!=='')$definition.=" COMMENT '".$db->real_escape_string($c['Comment'])."'";
                $plan[$table]['columns'][]=$col;$plan[$table]['changes'][]='MODIFY '.$definition;
            }
        }
        return $plan;
    }
    public static function apply(mysqli $db,bool $disposable=false): array
    {
        $name=$db->query('SELECT DATABASE()')->fetch_row()[0];
        if($disposable&&!preg_match('/^ccc_database_test_bflow_[a-f0-9]{8}$/D',$name))throw new RuntimeException('Backup bypass is restricted to disposable fixtures');
        $plan=self::plan($db);$backups=[];$db->query('SET SESSION lock_wait_timeout=10');
        foreach($plan as $table=>$p){
            if(!$disposable){
                $path='/tmp/ccc-currency-'.$name.'-'.$table.'-'.date('YmdHis').'-'.bin2hex(random_bytes(3)).'.ndjson';$old=umask(0077);$file=fopen($path,'x');umask($old);if(!$file)throw new RuntimeException('Currency backup unavailable');
                $schema=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch_row()[1];fwrite($file,json_encode(['schema'=>$schema,'columns'=>$p['columns']],JSON_THROW_ON_ERROR)."\n");
                $columns=array_unique(array_merge(['id'],$p['columns']));$query='SELECT '.implode(',',array_map(fn($c)=>'`'.$c.'`',$columns)).' FROM `'.$table.'`';
                $db->begin_transaction(MYSQLI_TRANS_START_READ_ONLY);$result=$db->query($query,MYSQLI_USE_RESULT);
                while($row=$result->fetch_assoc())fwrite($file,json_encode($row,JSON_THROW_ON_ERROR)."\n");$result->free();$db->commit();fclose($file);$backups[$table]=$path;
            }
            // MySQL type conversion retains whole-unit values. No business UPDATE is issued.
            $db->query('ALTER TABLE `'.$table.'` '.implode(',',$p['changes']));
        }
        return $backups;
    }
    public static function verifyBackups(mysqli $db): array
    {
        $name=$db->query('SELECT DATABASE()')->fetch_row()[0];$report=[];
        $normal=static function($value){if($value===null)return null;$v=(string)$value;if(str_contains($v,'.'))$v=rtrim(rtrim($v,'0'),'.');return $v==='-0'?'0':$v;};
        foreach(array_keys(self::FIELDS) as $table){
            $files=glob('/tmp/ccc-currency-'.$name.'-'.$table.'-*.ndjson');rsort($files);if(!$files){$report[$table]=['status'=>'NO_BACKUP'];continue;}
            $file=fopen($files[0],'r');$header=json_decode(fgets($file),true,512,JSON_THROW_ON_ERROR);$columns=$header['columns'];
            foreach($columns as $col)if(!preg_match('/^[a-z_]+$/D',$col))throw new RuntimeException('Invalid backup metadata');
            $current=[];$result=$db->query('SELECT id,'.implode(',',array_map(fn($c)=>'`'.$c.'`',$columns)).' FROM `'.$table.'`');while($row=$result->fetch_assoc())$current[(string)$row['id']]=$row;
            $checked=0;$missing=0;$different=0;
            while(($line=fgets($file))!==false){$old=json_decode($line,true,512,JSON_THROW_ON_ERROR);$checked++;$row=$current[(string)$old['id']]??null;if(!$row){$missing++;continue;}foreach($columns as $col)if($normal($old[$col])!==$normal($row[$col])){$different++;break;}}
            fclose($file);$report[$table]=['rows_checked'=>$checked,'missing_rows'=>$missing,'numeric_changes'=>$different,'existing_values_preserved'=>$missing===0&&$different===0];
        }
        return $report;
    }
}
