<?php
final class ModuleInvoiceAttachment
{
    private static function root(): string { return dirname(__DIR__,2).'/.module-flow-private/'.(int)ModuleFlowRuntime::$context['client_id']; }
    public static function upload(string $action,array $r,array $files): array
    {
        $db=ModuleFlowRuntime::db();$bid=(int)get_bid();$id=(int)($action==='uploadAttachment'?($r['log_id']??0):($r['id']??0));
        if($id<1)throw new InvalidArgumentException('Select the invoice or change log explicitly');
        if($action==='uploadAttachment'){$table='invoice_change_log';$col='image';$row=$db->query("SELECT l.id FROM invoice_change_log l JOIN invoice i ON i.id=l.invoice_id WHERE l.id=$id AND i.bid=$bid FOR UPDATE")->fetch_assoc();}
        else{$table='invoice';$col=(string)($r['col']??'');if(!in_array($col,['image','cancel_image'],true))throw new InvalidArgumentException('Unsupported attachment field');$columns=array_column($db->query('SHOW COLUMNS FROM invoice')->fetch_all(MYSQLI_ASSOC),'Field');if(!in_array($col,$columns,true))throw new InvalidArgumentException('Attachment field is unavailable');$row=$db->query("SELECT id FROM invoice WHERE id=$id AND bid=$bid FOR UPDATE")->fetch_assoc();}
        if(!$row)throw new DomainException('Attachment target is outside this branch');$file=$files['file']??[];
        if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??''))throw new InvalidArgumentException('Choose a valid attachment');
        if((int)$file['size']<1||(int)$file['size']>10485760)throw new InvalidArgumentException('Attachment must be no larger than 10 MB');
        $types=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','application/pdf'=>'pdf'];$mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);if(!isset($types[$mime]))throw new InvalidArgumentException('Use a JPG, PNG, WebP or PDF attachment');
        $root=self::root();if(!is_dir($root)&&!mkdir($root,0700,true)&&!is_dir($root))throw new RuntimeException('Attachment storage unavailable');
        $name=bin2hex(random_bytes(16)).'.'.$types[$mime];$path=$root.'/'.$name;if(!move_uploaded_file($file['tmp_name'],$path))throw new RuntimeException('Attachment could not be saved');chmod($path,0600);
        try{$value='module-flow:'.$name;$stmt=$db->prepare("UPDATE `$table` SET `$col`=? WHERE id=?");$stmt->bind_param('si',$value,$id);$stmt->execute();$stmt->close();}catch(Throwable $e){unlink($path);throw $e;}
        return ModuleFlowRuntime::response(0,'Attachment uploaded','',['id'=>$id]);
    }
    public static function download(array $r): void
    {
        $db=ModuleFlowRuntime::db();$id=(int)($r['id']??0);$bid=(int)get_bid();$row=$db->query("SELECT l.image FROM invoice_change_log l JOIN invoice i ON i.id=l.invoice_id WHERE l.id=$id AND i.bid=$bid")->fetch_assoc();
        if(!$row||!preg_match('/^module-flow:([a-f0-9]{32}\.(?:jpg|png|webp|pdf))$/D',(string)$row['image'],$m))throw new DomainException('Attachment is not available');
        ModuleFlowRuntime::sendFile(self::root().'/'.$m[1]);
    }
}
