<?php
declare(strict_types=1);
require_once __DIR__.'/school_years.php';

function sectionPreparationFields(): array
{
    return ['section_name','grade_level','schedule_type','am_in_start','am_in_end','am_late_threshold','am_out_start','am_out_end','pm_in_start','pm_in_end','pm_late_threshold','pm_out_start','pm_out_end'];
}

function sectionPreparationCommand(array $input): array
{
    $id=function($value): int {
        $parsed=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if ($parsed===false || is_bool($value)) throw new DomainException('Choose valid school years and sections.');
        return $parsed;
    };
    if (!is_array($input['section_ids'] ?? null) || !$input['section_ids']) throw new DomainException('Select at least one section.');
    $ids=array_map($id,$input['section_ids']); sort($ids,SORT_NUMERIC);
    if (count($ids)!==count(array_unique($ids))) throw new DomainException('Duplicate section selection.');
    return ['source_year_id'=>$id($input['source_year_id'] ?? null),'target_year_id'=>$id($input['target_year_id'] ?? null),'section_ids'=>$ids];
}

/** Caller owns transaction. Match names using the same collation as section storage. */
function sectionPreparationSnapshot(PDO $db, array $actor, array $command): array
{
    if (!$db->inTransaction()) throw new LogicException('Section preparation requires a transaction.');
    if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('School-year control is not initialized.');
    $years=$db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    $sections=$db->query('SELECT * FROM sections ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
    requireSchoolYearActivationAdmin($db,$actor,true);
    if (!enrollmentModelEnabled($db)) throw new DomainException('Annual enrollment is not enabled.');
    $yearsById=array_column($years,null,'id');
    $source=$yearsById[$command['source_year_id']] ?? null; $target=$yearsById[$command['target_year_id']] ?? null;
    if (count(array_filter($years,fn($y)=>$y['state']==='active'))!==1 || !$source || !in_array($source['state'],['active','closed'],true) || !$target || $target['state']!=='planned' || $target['starts_on']<=$source['ends_on']) throw new DomainException('Choose an active or closed source year and a later planned target year.');
    $byId=array_column($sections,null,'id'); $rows=[];
    $marks=implode(',',array_fill(0,count($command['section_ids']),'?'));
    $duplicates=$db->prepare("SELECT id FROM sections WHERE id IN ($marks) AND section_name=? AND grade_level=? ORDER BY id FOR UPDATE");
    $match=$db->prepare('SELECT id,is_active FROM sections WHERE school_year_id=? AND section_name=? AND grade_level=? ORDER BY id FOR UPDATE');
    foreach($command['section_ids'] as $id) {
        $section=$byId[$id] ?? null;
        if (!$section || !(int)$section['is_active'] || (int)$section['school_year_id']!==$command['source_year_id']) throw new DomainException('A selected source section is unavailable. Reload the source list.');
        $duplicates->execute(array_merge($command['section_ids'],[$section['section_name'],$section['grade_level']]));
        if (count($duplicates->fetchAll(PDO::FETCH_COLUMN))!==1) throw new DomainException('Selected source sections share a name and grade. Select only one of each.');
        $match->execute([$command['target_year_id'],$section['section_name'],$section['grade_level']]); $matches=$match->fetchAll(PDO::FETCH_ASSOC);
        if (count($matches)>1) throw new DomainException('Multiple target sections share a name and grade. Review the target sections first.');
        $destination=$matches[0] ?? null;
        $rows[]=['source_id'=>$id,'section'=>array_intersect_key($section,array_flip(sectionPreparationFields())),'status'=>$destination ? ((int)$destination['is_active'] ? 'existing' : 'archived') : 'create','destination'=>$destination];
    }
    return ['source'=>$source,'target'=>$target,'rows'=>$rows,'fingerprint'=>hash('sha256',json_encode([$command,$source,$target,$rows],JSON_THROW_ON_ERROR))];
}

function previewSectionPreparation(PDO $db, array $actor, array $input): array
{
    if ($db->inTransaction()) throw new LogicException('Section preview owns its transaction.');
    requireSchoolYearActivationAdmin($db,$actor);
    $command=sectionPreparationCommand($input);
    $db->beginTransaction();
    try { $preview=sectionPreparationSnapshot($db,$actor,$command); $db->commit(); return $preview; }
    catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}

function prepareSections(PDO $db, array $actor, array $input, string $expectedFingerprint): array
{
    if ($db->inTransaction()) throw new LogicException('Section preparation owns its transaction.');
    requireSchoolYearActivationAdmin($db,$actor);
    $command=sectionPreparationCommand($input);
    if (!preg_match('/\A[0-9a-f]{64}\z/',$expectedFingerprint)) throw new DomainException('Preview the sections before confirming.');
    $db->beginTransaction();
    try {
        $preview=sectionPreparationSnapshot($db,$actor,$command);
        if (!hash_equals($preview['fingerprint'],$expectedFingerprint)) throw new DomainException('Sections or school years changed. Preview the sections again before confirming.');
        $fields=array_merge(sectionPreparationFields(),['school_year_id','school_year','adviser_id','is_active']);
        $insert=$db->prepare('INSERT INTO sections ('.implode(',',$fields).') VALUES ('.implode(',',array_fill(0,count($fields),'?')).')');
        $counts=['created'=>0,'skipped'=>0]; securityAuditContext((int)$actor['id']);
        foreach($preview['rows'] as $row) {
            if ($row['status']!=='create') { $counts['skipped']++; continue; }
            $after=$row['section']+['school_year_id'=>$command['target_year_id'],'school_year'=>$preview['target']['label'],'adviser_id'=>null,'is_active'=>1];
            $insert->execute(array_map(fn($field)=>$after[$field],$fields));
            $id=(int)$db->lastInsertId();
            securityAuditChange($db,'section.prepare','route',$id,null,$after+['source_section_id'=>$row['source_id']]);
            $counts['created']++;
        }
        $db->commit(); return $counts;
    } catch(Throwable $error) { if($db->inTransaction()) $db->rollBack(); throw $error; }
}
