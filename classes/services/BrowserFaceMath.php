<?php
/** Same deterministic medoid/MAD aggregation as browser core.mjs. */
final class BrowserFaceMath
{
    private static function median(array $values): float {sort($values,SORT_NUMERIC);$n=count($values);return $n%2?$values[intdiv($n,2)]:($values[$n/2-1]+$values[$n/2])/2;}
    public static function aggregate(array $samples): array
    {
        if(count($samples)<3 || count($samples)>5)throw new InvalidArgumentException('Three to five observations required');
        $samples=array_map(['BrowserFaceService','vector'],$samples);
        foreach($samples as &$v){$n=sqrt(array_sum(array_map(fn($x)=>$x*$x,$v)));$v=array_map(fn($x)=>$x/$n,$v);}unset($v);
        $distances=array_map(fn($a)=>self::median(array_map(fn($b)=>1-BrowserFaceService::cosine($a,$b),$samples)),$samples);
        $medoid=$samples[array_search(min($distances),$distances,true)];
        $deviation=array_map(fn($v)=>max(0,1-BrowserFaceService::cosine($v,$medoid)),$samples);$centre=self::median($deviation);$mad=self::median(array_map(fn($v)=>abs($v-$centre),$deviation));
        $retained=[];foreach($samples as $i=>$v)if($deviation[$i]<=$centre+3*max($mad,1e-6))$retained[]=$v;
        if(count($retained)<3)throw new InvalidArgumentException('Please capture this face again');
        $mean=array_fill(0,128,0.0);foreach($retained as $v)foreach($v as $i=>$x)$mean[$i]+=$x/count($retained);
        $n=sqrt(array_sum(array_map(fn($x)=>$x*$x,$mean)));if($n<1e-8)throw new InvalidArgumentException('Please capture this face again');
        return array_map(fn($x)=>$x/$n,$mean);
    }
}
