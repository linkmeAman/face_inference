<?php
final class ModuleCredentials
{
    public static function verify(string $plain,?string $stored): bool
    {
        if($plain===''||!$stored)return false;
        return password_get_info($stored)['algo']!==null ? password_verify($plain,$stored) : hash_equals($stored,$plain);
    }
    public static function login(mysqli $db,array $r): ?array
    {
        $mobile=preg_replace('/[\s()-]+/','',(string)($r['username']??''));$country=ltrim((string)($r['country_code']??''),'+');$secret=(string)($r['password']??'');
        if(!preg_match('/^\d{8,15}$/D',$mobile)||!preg_match('/^\d{1,4}$/D',$country)||$secret==='')return null;
        $stmt=$db->prepare("SELECT u.*,u.type user_type,cd.db_name,cd.inst_name,cd.logo FROM user u JOIN client_db cd ON cd.id=u.client_id WHERE u.mobile=? AND REPLACE(u.country_code,'+','')=? AND u.park=0 AND u.inactive=0 AND cd.inactive=0");$stmt->bind_param('ss',$mobile,$country);$stmt->execute();$rows=$stmt->get_result()->fetch_all(MYSQLI_ASSOC);$stmt->close();$matches=[];
        foreach($rows as $row){if(empty($r['client_id']) && strtolower(explode(':',(string)($_SERVER['HTTP_HOST']??''))[0])==='admin.creativeculturalcentre.in' && $row['db_name']!=='ccc_database')continue;if(!empty($r['client_id'])&&(int)$r['client_id']!==(int)$row['client_id'])continue;if(!empty($r['user_type'])&&$r['user_type']!==$row['user_type'])continue;if(self::verify($secret,$row['password'])||self::verify($secret,$row['mpin']))$matches[]=$row;}
        // Do not pick the first tenant when several identities share credentials.
        if(count($matches)!==1)return null;$row=$matches[0];$uid=(int)$row['id'];
        foreach(['password','mpin'] as $col)if($row['db_name']==='ccc_database' && self::verify($secret,$row[$col]) && password_get_info((string)$row[$col])['algo']===null){$hash=password_hash($secret,PASSWORD_DEFAULT);$stmt=$db->prepare("UPDATE user SET `$col`=? WHERE id=?");$stmt->bind_param('si',$hash,$uid);$stmt->execute();$stmt->close();}
        unset($row['password'],$row['mpin']);$row['image_url']='';return $row;
    }
    public static function change(string $field,array $r): array
    {
        $db=ModuleFlowRuntime::db();$uid=(int)get_uid();$row=$db->query('SELECT password,mpin FROM user WHERE id='.$uid.' FOR UPDATE')->fetch_assoc();
        if(!self::verify((string)($r['current']??''),$row['password'])&&!self::verify((string)($r['current']??''),$row['mpin']))throw new DomainException('The current credential is incorrect');
        $plain=(string)($r['new']??'');if(($field==='password'&&(strlen($plain)<10||strlen($plain)>72))||($field==='mpin'&&!preg_match('/^\d{6}$/D',$plain)))throw new InvalidArgumentException($field==='password'?'Use a password of 10 to 72 characters':'Use a six digit PIN');
        $hash=password_hash($plain,PASSWORD_DEFAULT);$stmt=$db->prepare("UPDATE user SET `$field`=?,auth_epoch=auth_epoch+1 WHERE id=?");$stmt->bind_param('si',$hash,$uid);$stmt->execute();$stmt->close();$db->query('UPDATE user_device SET park=1,logout=1 WHERE user_id='.$uid);
        return ModuleFlowRuntime::response(0,'Credential saved. Sign in again with the new credential');
    }
}
