<?php
final class ModuleMemberState
{
    public static function park(array $r): array
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$cid=(int)ModuleFlowRuntime::$context['client_id'];$park=(int)($r['park_value']??1);
        if(!in_array($park,[0,1],true))throw new InvalidArgumentException('Use member removal or restore; history cannot be erased');
        $rows=$db->query('SELECT id,contact_id,bid FROM member WHERE '.ModuleFlowSql::clauses($r['d']??[]).' FOR UPDATE')->fetch_all(MYSQLI_ASSOC);if(!$rows)throw new InvalidArgumentException('Select a member');
        foreach($rows as $row){if((int)$row['bid']!==$bid)throw new DomainException('Member is outside this branch');$id=(int)$row['id'];$contact=(int)$row['contact_id'];$db->query("UPDATE member SET park=$park,modified_by=".(int)get_uid()." WHERE id=$id");
            $users=$db->query("SELECT id FROM user WHERE client_id=$cid AND contact_id=$contact AND type='M'")->fetch_all(MYSQLI_ASSOC);
            foreach($users as $u){$uid=(int)$u['id'];$db->query("UPDATE user SET park=$park,auth_epoch=auth_epoch+1 WHERE id=$uid");$db->query("UPDATE user_device SET park=1,logout=1 WHERE user_id=$uid");}
        }
        return ModuleFlowRuntime::response(0,$park?'Member removed; financial and attendance history retained':'Member restored; sign in again');
    }
}
