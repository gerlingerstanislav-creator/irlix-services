<?php

namespace App\Migration\Core;

/**
 * Inter-service Client identity proof. Old numeric client IDs are unrelated.
 * Corporate UUID -> Employees mappings may corroborate equal legacy project
 * labels when several Clients share that label.
 */
final class TimesheetsClientResolver
{
    public static function unique(
        array $labelClientIds,
        ?int $accountEmployeeId,
        ?int $salesEmployeeId,
        array $managersByClient
    ): ?int {
        $ids=array_values(array_unique(array_map('intval',$labelClientIds)));
        if(count($ids)===1)return $ids[0];
        if(count($ids)<2)return null;
        $checks=[];
        foreach(['account'=>$accountEmployeeId,'sales'=>$salesEmployeeId] as $role=>$employee) {
            if($employee===null)continue;
            $matches=array_values(array_filter($ids,
                static fn(int $id):bool => isset($managersByClient[$id][$role])
                    && $managersByClient[$id][$role]!==null
                    && (int)$managersByClient[$id][$role]===$employee));
            // A confirmed UUID pointing to another client is a contradiction,
            // not permission to ignore that employee and use the other role.
            if(count($matches)!==1)return null;
            $checks[]=$matches[0];
        }
        return $checks && count(array_unique($checks))===1 ? $checks[0] : null;
    }
}
