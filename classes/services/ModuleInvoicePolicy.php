<?php
require_once __DIR__ . '/ModuleFollowupService.php';

final class ModuleInvoicePolicy
{
    private static function money($v,string $field): int
    {
        if(is_float($v)&&is_finite($v)&&abs($v*100-round($v*100))<0.000001)$v=number_format($v,2,'.','');
        if(!is_scalar($v)||!preg_match('/^\d+(?:\.\d{1,2})?$/D',(string)$v))throw new InvalidArgumentException('Invalid currency value: '.$field);
        if((float)$v>100000000)throw new InvalidArgumentException('Currency amount exceeds the supported limit');
        return (int)round((float)$v*100);
    }
    public static function prepare(array &$r): void
    {
        $db=ModuleFlowRuntime::active()?ModuleFlowRuntime::db():(new dbconnection())->connect($_SESSION['db_name']??'ccc_database');$cid=(int)($r['contact_id']??0);$bid=(int)get_bid();
        if($cid<1||!$db->query('SELECT id FROM contact WHERE id='.$cid.' AND bid='.$bid.' AND park=0')->num_rows)throw new DomainException('Select an active contact in this branch');
        $member=$db->query('SELECT park,status,bid FROM member WHERE contact_id='.$cid)->fetch_assoc();
        if($member && ((int)$member['park']!==0 || (int)$member['status']!==1))throw new DomainException('Reactivate the member explicitly before billing');
        if(!empty($r['price_override_reason'])){ $r['price_override_reason']=trim((string)$r['price_override_reason']);if(strlen($r['price_override_reason'])<10)throw new InvalidArgumentException('Explain the approved price or entitlement change in at least ten characters'); }
        $items=$r['invoice_item']??[];if(!is_array($items)||!$items)throw new InvalidArgumentException('Add at least one invoice line');
        $amount=0;$tax=0;$total=0;
        foreach($items as &$item){
            $sid=(int)($item['service_id']??0);$cat=(int)($item['category_id']??0);$batch=(int)($item['batch_id']??0);
            $service=$db->query('SELECT * FROM service WHERE id='.$sid.' AND category_id='.$cat.' AND bid='.$bid.' AND park=0 AND inactive=0')->fetch_assoc();
            if(!$service)throw new DomainException('The selected package is not available in this category and branch');
            if($batch){
                $b=$db->query('SELECT id,category_id,bid,park,`limit`,parent_id FROM batch WHERE id='.$batch.' FOR UPDATE')->fetch_assoc();
                if(!$b||(int)$b['category_id']!==$cat||(int)$b['bid']!==$bid||(int)$b['park']!==0)throw new DomainException('The batch does not match the package category and branch');
                if((int)$b['limit']>0 && (int)($r['quotation']??0)===0){
                    $start=$db->real_escape_string($item['start_date']??'');$end=$db->real_escape_string($item['end_date']??'');
                    $count=(int)$db->query("SELECT COUNT(DISTINCT i.contact_id) FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE ii.batch_id=$batch AND i.quotation=0 AND i.park=0 AND ii.park=0 AND ii.start_date<='$end' AND ii.end_date>='$start' AND i.contact_id<>$cid")->fetch_row()[0];
                    if($count>=(int)$b['limit'])throw new DomainException('This batch is full');
                }
            }
            foreach(['start_date','end_date'] as $field){$d=DateTime::createFromFormat('!Y-m-d',(string)($item[$field]??''));if(!$d||$d->format('Y-m-d')!==$item[$field])throw new InvalidArgumentException('Invalid package '.$field);}
            if($item['end_date']<$item['start_date'])throw new InvalidArgumentException('Package end date precedes its start');
            $usage=$item['sessions_used']??$item['session_used']??0;
            if(isset($item['sessions_used'],$item['session_used']) && (int)$item['sessions_used']!==(int)$item['session_used'])throw new InvalidArgumentException('Conflicting session usage fields');
            if((int)$usage!==0)throw new InvalidArgumentException('New package usage must start at zero; use an audited transfer for carried usage');
            $item['sessions_used']=$item['session_used']=0;
            foreach(['service_id','category_id','batch_id','offer_id','module_id','billing_cycle','free_trial'] as $field)$item[$field]=(int)($item[$field]??0);
            foreach(['referral_code','promo_code'] as $field)if(isset($item[$field])&&!preg_match('/^[A-Za-z0-9 _-]*$/D',(string)$item[$field]))throw new InvalidArgumentException('Invalid referral/promotion code');
            if(!ctype_digit((string)($item['sessions']??'')))throw new InvalidArgumentException('Invalid package session entitlement');
            if((int)$item['sessions']!==(int)$service['sessions'] && empty($r['price_override_reason']))throw new InvalidArgumentException('Package session entitlement requires an approved override reason');
            $base=self::money($item['item_amount']??0,'line amount');$discount=self::money($item['item_discount']??0,'discount');
            if($base!==self::money($service['amount']??0,'package amount') && empty($r['price_override_reason']))throw new DomainException('Price override requires an authorized reason');
            if(!empty($r['price_override_reason']) && !(new session())->access(get_uid(),(new dbconnection())->module('Invoice'),3))throw new DomainException('Price override requires invoice administration');
            if($discount>$base)throw new InvalidArgumentException('Discount exceeds the line amount');
            $rate=(float)($service['tax']??0);$type=(int)($service['tax_type']??0);$net=$base-$discount;
            $lineTax=$type===2?(int)round($net*$rate/100):($type===1?(int)round($net-$net/(1+$rate/100)):0);
            $lineTotal=$net+($type===2?$lineTax:0);
            if(self::money($item['item_total']??0,'line total')!==$lineTotal||self::money($item['item_tax_amount']??0,'line tax')!==$lineTax)throw new InvalidArgumentException('Line tax or total does not match the package calculation');
            $item['item_amount']=$base/100;$item['item_discount']=$discount/100;$item['item_tax']=$rate;$item['item_tax_type']=$type;$item['item_tax_amount']=$lineTax/100;$item['item_total']=$lineTotal/100;
            $amount+=$base;$tax+=$lineTax;$total+=$lineTotal;
        }unset($item);$r['invoice_item']=$items;
        if(self::money($r['amount']??0,'invoice amount')!==$amount||self::money($r['tax_amount']??0,'invoice tax')!==$tax||self::money($r['total']??0,'invoice total')!==$total)throw new InvalidArgumentException('Invoice totals do not match its lines');
        $paid=0;foreach((array)($r['payment']??[]) as $payment){$pay=self::money($payment['amount']??0,'payment');if((int)($payment['clear']??0)===1){if((int)($payment['pay_mode']??0)===4 && $pay>0)throw new DomainException('Online clearance requires verified settlement');$paid+=$pay;}}
        if($paid>$total||self::money($r['balance']??0,'balance')!==$total-$paid)throw new InvalidArgumentException('Payment clearance and balance do not match the invoice');
        $r['amount']=$amount/100;$r['tax_amount']=$tax/100;$r['total']=$total/100;$r['balance']=($total-$paid)/100;
        if(ModuleFlowRuntime::active()) ModuleFlowRuntime::lock('billing-numbering');
    }
    public static function recordOverride(mysqli $db,int $invoice,array $r): void
    {
        if(empty($r['price_override_reason']))return;$payload=json_encode(['invoice_id'=>$invoice,'reason'=>$r['price_override_reason'],'items'=>$r['invoice_item']],JSON_THROW_ON_ERROR);$uid=(int)get_uid();$bid=(int)get_bid();
        $stmt=$db->prepare("INSERT INTO module_flow_outbox(actor_id,branch_id,channel,payload,state) VALUES(?,?,'AUDIT',?,'RECORDED')");$stmt->bind_param('iis',$uid,$bid,$payload);$stmt->execute();$stmt->close();
    }
    public static function convert(mysqli $db,int $contact,array $items,string $date,bool $paid,int $bid): void
    {
        if ($bid <= 0) {
            $bid = !empty(get_bid()) ? (int)get_bid() : (int)($_SESSION['bid'] ?? 1);
        }
        $setting=$db->query('SELECT inquiry_convert FROM invoice_settings WHERE bid='.$bid)->fetch_assoc();
        $mode=(int)($setting['inquiry_convert']??0);
        if($mode===0||!$paid)return;
        $where="i.contact_id={$contact} AND (i.bid={$bid} OR i.bid=0) AND i.park=0 AND i.converted=0";
        if($mode===1){
            $cats=array_values(array_filter(array_unique(array_map(fn($i)=>(int)($i['category_id']??0),$items))));
            if(!$cats)return;
            $rows=$db->query("SELECT DISTINCT i.id FROM inquiry i JOIN inquiry_category ic ON ic.inquiry_id=i.id WHERE {$where} AND ic.park=0 AND ic.category_id IN(".implode(',',$cats).")")->fetch_all(MYSQLI_ASSOC);
        } else {
            $rows=$db->query("SELECT DISTINCT i.id FROM inquiry i WHERE {$where}")->fetch_all(MYSQLI_ASSOC);
        }
        foreach($rows as $row){
            $id=(int)$row['id'];
            $stmt=$db->prepare('UPDATE inquiry SET converted=1,doc=? WHERE id=? AND converted=0');
            $stmt->bind_param('si',$date,$id);
            $stmt->execute();
            $stmt->close();
            $followups=$db->query('SELECT id FROM followup WHERE master=0 AND master_id='.$id.' AND unfollow=0')->fetch_all(MYSQLI_ASSOC);
            foreach($followups as $f)ModuleFollowupService::cancelJobs($db,(int)$f['id']);
            $db->query('UPDATE followup SET unfollow=1,status=3 WHERE master=0 AND master_id='.$id.' AND unfollow=0');
        }
    }
}
