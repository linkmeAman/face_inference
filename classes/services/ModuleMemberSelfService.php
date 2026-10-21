<?php
final class ModuleMemberSelfService
{
    public static function read(): array
    {
        $db=ModuleFlowRuntime::db();$contact=(int)ModuleFlowRuntime::$context['contact_id'];$bid=(int)get_bid();
        $profile=$db->query("SELECT m.code,m.status,m.doj,c.fname,c.lname,c.mobile,c.country_code FROM member m JOIN contact c ON c.id=m.contact_id WHERE m.contact_id=$contact AND m.bid=$bid AND m.park=0 AND c.park=0")->fetch_assoc();
        if(!$profile)throw new DomainException('Your member profile is not active in this branch');
        $invoices=$db->query("SELECT id,invoice,date,total,balance FROM invoice WHERE contact_id=$contact AND bid=$bid AND quotation=0 AND park=0 ORDER BY id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        $packages=$db->query("SELECT ii.id,ii.batch_id,ii.start_date,ii.end_date,ii.sessions,ii.sessions_used FROM invoice_item ii JOIN invoice i ON i.id=ii.invoice_id WHERE i.contact_id=$contact AND i.bid=$bid AND i.quotation=0 AND i.park=0 AND ii.park=0 ORDER BY ii.id DESC LIMIT 100")->fetch_all(MYSQLI_ASSOC);
        $attendance=$db->query("SELECT id,date,in_time,out_time,batch_id,negative FROM attendance WHERE contact_id=$contact AND bid=$bid AND type='M' AND park=0 ORDER BY date DESC,id DESC LIMIT 200")->fetch_all(MYSQLI_ASSOC);
        return ModuleFlowRuntime::response(0,'','',['profile'=>$profile,'invoices'=>$invoices,'packages'=>$packages,'attendance'=>$attendance]);
    }
}
