<?php
// Evaluation-only, no connection and no writes. Real production matching function.
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__).'/classes/services/BrowserFaceService.php';
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if(!is_array($input) || count($input)>1000)throw new InvalidArgumentException('Bounded cases required');
$out=[];foreach($input as $case){
    $winner=BrowserFaceService::matchObservations($case['observations'],$case['templates'],(float)$case['threshold']);
    if(!isset($case['duplicate_threshold'])){$out[]=$winner;continue;}
    $query=BrowserFaceService::vector($case['observations'][0]);$highest=null;
    foreach($case['templates'] as $template){$score=BrowserFaceService::cosine($query,BrowserFaceService::vector($template['vector']));if($highest===null || $score>$highest['score'])$highest=['member_id'=>$template['member_id'],'score'=>$score];}
    $out[]=['winner'=>$winner,'duplicate'=>($highest!==null && $highest['score']>=(float)$case['duplicate_threshold'])];
}echo json_encode($out,JSON_THROW_ON_ERROR);
