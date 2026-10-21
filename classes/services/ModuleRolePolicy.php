<?php
/**
 * ModuleRolePolicy manages role-based access control (RBAC) templates,
 * inter-module permission dependencies, and parent-child permission inheritance.
 *
 * Architecture Invariants (ADR-029):
 * 1. Admin Role Parity: The 'admin' role receives Level 3 (Read, Write, Delete)
 *    across all active operational modules (Batch, Invoice, Inquiry, Member, Employee, etc.).
 * 2. Active Tenant Filter: Role templates must strictly intersect with active client modules;
 *    inactive tenant modules (e.g. Target 21) must never be assigned or leaked into user permissions.
 * 3. Parent-Child Propagation: Sub-modules (e.g. Member 18, Payment 19, Workshift 22)
 *    automatically inherit the effective permission level of their parent module.
 */
final class ModuleRolePolicy
{
    /**
     * Resolves the permission level mapping for a given standard role template.
     *
     * @param string $role Template identifier ('admin', 'trainer', etc.)
     * @param array $modules Active client module definitions from client_module
     * @return array Module ID => Permission Level (0..3)
     */
    public static function template(string $role,array $modules): array
    {
        // Extract IDs of modules actively provisioned for this tenant
        $activeIds=array_map(fn($m)=>(int)$m['module_id'],$modules);
        $out=[];
        // ADR-029: 'admin' role receives level 3 across operational modules; trainer receives restricted scope
        foreach($modules as $m){$id=(int)$m['module_id'];$out[$id]=$role==='trainer'?(in_array($id,[6,14,83],true)?2:(in_array($id,[1,8,12,15,23,55,61],true)?1:0)):3;}
        $p=self::dependencies($out);
        // Ensure inactive tenant modules are never granted
        return array_intersect_key($p,array_flip($activeIds));
    }
    public static function dependencies(array $p): array
    {
        $rules=[2=>[8=>1,9=>1,10=>1,11=>1,12=>2,18=>2,19=>2],3=>[10=>1,11=>1,12=>2,13=>2],8=>[10=>1,11=>1,14=>2],11=>[12=>2,21=>1],6=>[8=>1,12=>1,14=>2],9=>[10=>1],23=>[14=>2]];
        foreach($rules as $mid=>$deps) if(($p[$mid]??0)>($mid===6?0:1))foreach($deps as $id=>$level)$p[$id]=max($p[$id]??0,$level);
        return $p;
    }
    public static function children(array $p,array $modules): array
    {
        for($n=0;$n<count($modules);$n++){
            $changed=false;foreach($modules as $m){$mid=(int)$m['module_id'];$parent=(int)($m['parent_id']??0);if($parent && array_key_exists($parent,$p) && ($p[$mid]??null)!==$p[$parent]){$p[$mid]=$p[$parent];$changed=true;}}
            if(!$changed)break;
        }
        return $p;
    }
}
