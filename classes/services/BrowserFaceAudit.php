<?php
require_once __DIR__.'/FaceAttendanceConfig.php';
/** Sparse, bounded operational events. Never accepts payloads, vectors or exception text. */
final class BrowserFaceAudit
{
    public static function write(string $event,array $metadata=[]): bool
    {
        $path=FaceAttendanceConfig::getEnv('FACE_WEB_EVENT_LOG');
        $root=realpath(dirname(__DIR__,2));$dir=$path!==''?realpath(dirname($path)):false;
        if(!$dir || strpos($dir.DIRECTORY_SEPARATOR,$root.DIRECTORY_SEPARATOR)===0 || !is_writable($dir) || (fileperms($dir)&0007)!==0 || is_link($path))return false;
        $safe=['timestamp'=>gmdate('c'),'event'=>$event,'release'=>'frictionless-20261016-1'];
        foreach(['test_attendance_id','test_attendance_saved','update_face','reference_gallery_sha256','comparison_source','capture_session','reference_calibration_id','matched_member_id','matched_calibration_id','capture_saved_as_reference','request_id','operation','tenant','branch_id','batch_id','session_id','scan_id','member_id','enrollment_id','calibration_id','enrollment_status','decision','reason','duration_ms','response_status','persistence_succeeded','persistence_outcome','attendance_saved','response_state','idempotent_replay','observation_count','candidate_count','test_only','actor_id',
            // Administrator rollout changes: scalar setting names and counts only.
            'previous_mode','new_mode','scope','scope_type','result','error_code','change_count','config_sha256'] as $key){
            $v=$metadata[$key] ?? null;
            if(is_bool($v) || is_int($v) || (is_float($v) && is_finite($v)))$safe[$key]=$v;
            elseif(is_string($v) && strlen($v)<=100 && preg_match('/^[A-Za-z0-9_.:-]*$/D',$v))$safe[$key]=$v;
        }
        $lock=@fopen($path.'.lock','c');if(!$lock)return false;@chgrp($path.'.lock',filegroup($dir));@chmod($path.'.lock',0660);
        if(!flock($lock,LOCK_EX)){fclose($lock);return false;}
        try{
            // At most 3 x 2MiB; lock remains stable during atomic rotation.
            if(is_file($path) && filesize($path)>2*1024*1024){if(is_file($path.'.2'))unlink($path.'.2');if(is_file($path.'.1'))rename($path.'.1',$path.'.2');rename($path,$path.'.1');}
            $out=@fopen($path,'ab');if(!$out)return false;@chgrp($path,filegroup($dir));@chmod($path,0660);
            $written=fwrite($out,json_encode($safe,JSON_THROW_ON_ERROR)."\n");fflush($out);fclose($out);clearstatcache(true,$path);return $written!==false;
        }finally{flock($lock,LOCK_UN);fclose($lock);}
    }
}
