<?php
final class ModulePaymentPolicy
{
    private static array $invoices=[];
    public static function prepare(string $action,array &$r): void
    {
        $db=ModuleFlowRuntime::db();$ids=[];
        if(!empty($r['invoice_id']))$ids[]=(int)$r['invoice_id'];
        $filter=$r[in_array($action,['park','delete'],true)?'d':'filter']??[];
        if($filter){$rows=$db->query('SELECT * FROM payment WHERE '.ModuleFlowSql::clauses($filter).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);$ids=array_merge($ids,array_map('intval',array_column($rows,'invoice_id')));}
        foreach(array_unique($ids) as $id){$bid=(int)get_bid();if(!$db->query("SELECT id FROM invoice WHERE id=$id AND bid=$bid AND park=0 AND quotation=0 FOR UPDATE")->num_rows)throw new DomainException('Choose an active invoice in this branch');if(!empty($r['contact_id'])){$contact=(int)$r['contact_id'];if(!$db->query("SELECT id FROM invoice WHERE id=$id AND contact_id=$contact")->num_rows)throw new DomainException('Payment invoice does not belong to the selected contact');}self::$invoices[$id]=true;}
        foreach($rows??[] as $payment){$values=[];foreach($r['u']??[] as $v)$values[$v['col']]=$v['val'];if((int)($values['pay_mode']??$payment['pay_mode'])===4 && (int)($values['clear']??$payment['clear'])===1)throw new DomainException('Online clearance requires a verified provider callback');}
        if(!$ids)throw new InvalidArgumentException('Select a payment or invoice');
        if(isset($r['amount']))self::money($r['amount']);
        $changes=[];foreach($r['u']??[] as $v){$changes[$v['col']]=$v['val'];if($v['col']==='amount')self::money($v['val']);}
        $clear=(int)($changes['clear']??$r['clear']??0);$mode=(int)($changes['pay_mode']??$r['pay_mode']??0);
        if($mode===4 && $clear===1)throw new DomainException('Online payment clearance requires a verified provider callback');
        if($clear===-3 && !(new session())->access(get_uid(),(new dbconnection())->module('Payment'),3))throw new DomainException('Balance discard requires payment administration');
        if(isset($changes['amount']) && !(new session())->access(get_uid(),(new dbconnection())->module('Payment'),3))throw new DomainException('Changing a payment amount requires payment administration');
    }
    private static function money($v): void
    {
        if(is_float($v)&&is_finite($v)&&abs($v*100-round($v*100))<0.000001)$v=number_format($v,2,'.','');
        if(!is_scalar($v)||!preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$v)||(float)$v>100000000)throw new InvalidArgumentException('Use a nonnegative currency amount with at most two decimal places');
    }
    public static function finalize(): void
    {
        $db=ModuleFlowRuntime::db();
        foreach(array_keys(self::$invoices) as $id){
            $row=$db->query('SELECT total FROM invoice WHERE id='.$id.' FOR UPDATE')->fetch_assoc();$total=(int)round((float)$row['total']*100);
            $payments=$db->query('SELECT amount,`clear`,pay_mode FROM payment WHERE invoice_id='.$id.' AND park=0')->fetch_all(MYSQLI_ASSOC);$settled=0;
            foreach($payments as $p){self::money($p['amount']);if(in_array((int)$p['clear'],[1,-3],true))$settled+=(int)round((float)$p['amount']*100);}
            if($settled>$total)throw new DomainException('Cleared payments and discarded balance exceed the invoice total');
            $balance=($total-$settled)/100;$stmt=$db->prepare('UPDATE invoice SET balance=? WHERE id=?');$stmt->bind_param('di',$balance,$id);$stmt->execute();$stmt->close();
        }
    }
}
