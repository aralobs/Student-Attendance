<?php
declare(strict_types=1);
require_once __DIR__ . '/school_years.php';

/** Private source data is hashed in memory, never copied into inventory results or logs. */
function enrollmentMigrationSources(PDO $db): array
{
    $source=[];
    foreach (['sections','students','attendance'] as $table) {
        $source[$table]=$db->query("SELECT *,UNIX_TIMESTAMP(created_at) AS _created_epoch,UNIX_TIMESTAMP(updated_at) AS _updated_epoch FROM $table ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    }
    return $source;
}

/** Read-only: IDs and reason codes only. Cached placement is evidence of ambiguity, never ownership. */
function inventoryEnrollmentHistory(PDO $db): array
{
    $source=enrollmentMigrationSources($db);
    $fingerprintSource=$source;
    foreach ($fingerprintSource['sections'] as &$row) unset($row['school_year_id']);
    unset($row);
    foreach ($fingerprintSource['attendance'] as &$row) unset($row['enrollment_id'],$row['assignment_id']);
    unset($row);
    // TIMESTAMP strings depend on connection time_zone; epoch values do not.
    foreach ($fingerprintSource as &$rows) {
        foreach ($rows as &$row) unset($row['created_at'],$row['updated_at']);
        unset($row);
    }
    unset($rows);
    $issues=[];
    $add=static function(string $type,int $id,string $code) use (&$issues): void {
        $issues[$type.':'.$id.':'.$code]=['record_type'=>$type,'record_id'=>$id,'code'=>$code];
    };
    $sections=array_column($source['sections'],null,'id');
    $students=array_column($source['students'],null,'id');
    $years=$db->query('SELECT id,starts_on,ends_on FROM school_years ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $enrollments=$db->query('SELECT * FROM student_enrollments ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $assignments=$db->query('SELECT * FROM enrollment_sections ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $enrollmentById=array_column($enrollments,null,'id');
    $assignmentById=array_column($assignments,null,'id');
    foreach ($sections as $id=>$section) {
        if (trim((string)$section['school_year'])==='') $add('section',(int)$id,'blank_year_label');
        if ($section['school_year_id']===null) $add('section',(int)$id,'unmapped_section');
    }
    foreach ($students as $id=>$student) {
        $section=$sections[$student['section_id']??0]??null;
        if (!$section) $add('student',(int)$id,'missing_section');
        if (trim((string)($student['school_year']??''))==='') $add('student',(int)$id,'blank_year_label');
        if ($section && ((!empty($student['school_year']) && $student['school_year']!==$section['school_year'])
            || (!empty($student['grade_level']) && $student['grade_level']!==$section['grade_level']))) {
            $add('student',(int)$id,'placement_disagreement');
        }
        if (!empty($student['previous_school_year']) || !empty($student['previous_section_id']) || !empty($student['previous_grade_level'])) {
            $add('student',(int)$id,'previous_placement_without_dates');
        }
        $hasAssignment=false;
        foreach ($enrollments as $enrollment) {
            if ((int)$enrollment['student_id']!==(int)$id || $enrollment['state']==='cancelled') continue;
            foreach ($assignments as $assignment) if ((int)$assignment['enrollment_id']===(int)$enrollment['id']) $hasAssignment=true;
        }
        if (!$hasAssignment) $add('student',(int)$id,'ambiguous_assignment');
    }
    foreach ($source['attendance'] as $attendance) {
        $id=(int)$attendance['id']; $student=$students[$attendance['student_id']]??null;
        if (!$student) $add('attendance',$id,'missing_profile');
        if ($student && $attendance['date']<substr((string)$student['created_at'],0,10)) $add('attendance',$id,'attendance_before_current_placement');
        $inYear=false;
        foreach ($years as $year) if ($attendance['date']>=$year['starts_on'] && $attendance['date']<=$year['ends_on']) $inYear=true;
        if (!$inYear) $add('attendance',$id,'attendance_outside_year');
        $enrollment=$enrollmentById[$attendance['enrollment_id']??0]??null;
        $assignment=$assignmentById[$attendance['assignment_id']??0]??null;
        if (!$enrollment || !$assignment) $add('attendance',$id,'unmapped_attendance');
        elseif ((int)$enrollment['student_id']!==(int)$attendance['student_id'] || (int)$assignment['enrollment_id']!==(int)$enrollment['id']
            || $attendance['date']<$enrollment['starts_on'] || $attendance['date']>$enrollment['ends_on']
            || $attendance['date']<$assignment['starts_on'] || $attendance['date']>$assignment['ends_on']) $add('attendance',$id,'invalid_attendance_ownership');
    }
    ksort($issues,SORT_STRING);
    return ['counts'=>['students'=>count($students),'sections'=>count($sections),'attendance'=>count($source['attendance']),
        'enrollments'=>count($enrollments),'assignments'=>count($assignments),'issues'=>count($issues)],
        'issues'=>array_values($issues),'fingerprint'=>hash('sha256',json_encode($fingerprintSource,JSON_THROW_ON_ERROR))];
}

function enrollmentMigrationId($id): int
{
    $valid=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
    if ($valid===false) throw new InvalidArgumentException('Mapping requires positive IDs.');
    return $valid;
}

function enrollmentMigrationKey($key): string
{
    if (!is_string($key) || !preg_match('/\A[A-Za-z0-9_.:-]{1,64}\z/',$key)) throw new InvalidArgumentException('Mapping requires stable local keys.');
    return $key;
}

function enrollmentMigrationDates(array $row): array
{
    $start=validatedSchoolYearDate($row['starts_on']??null); $end=validatedSchoolYearDate($row['ends_on']??null);
    if ($start>$end) throw new InvalidArgumentException('Mapping dates are reversed.');
    return [$start,$end];
}

/** Validate all JSON structure before opening a transaction; database-dependent checks precede DML too. */
function enrollmentMigrationValidate(array $map): void
{
    if (!is_string($map['inventory_fingerprint']??null) || !preg_match('/\A[0-9a-f]{64}\z/',$map['inventory_fingerprint'])) throw new InvalidArgumentException('Inventory fingerprint is required.');
    foreach (['school_years','sections','enrollments','attendance'] as $name) {
        if (!isset($map[$name]) || !is_array($map[$name]) || !array_is_list($map[$name])) throw new InvalidArgumentException('Mapping lists are required.');
    }
    $keys=[]; $natural=[]; $assignmentKeys=[]; $yearLabels=[]; $yearIds=[]; $sectionIds=[]; $attendanceIds=[];
    foreach ($map['school_years'] as $year) {
        if (!is_array($year)) throw new InvalidArgumentException('Invalid year mapping.');
        schoolYearLabelStart($year['label']??''); enrollmentMigrationDates($year);
        if (isset($yearLabels[$year['label']])) throw new InvalidArgumentException('Duplicate year mapping.');
        $yearLabels[$year['label']]=true;
        if (isset($year['id'])) { $id=enrollmentMigrationId($year['id']); if (isset($yearIds[$id])) throw new InvalidArgumentException('Duplicate year ID.'); $yearIds[$id]=true; }
    }
    $yearRef=static function(array $row) use ($yearLabels,$yearIds): string {
        if (isset($row['school_year_id'])) {
            $id=enrollmentMigrationId($row['school_year_id']);
            if (!isset($yearIds[$id])) throw new InvalidArgumentException('Every referenced year must be explicitly verified.');
            return 'id:'.$id;
        }
        $label=$row['school_year_label']??null;
        if (!is_string($label) || !isset($yearLabels[$label])) throw new InvalidArgumentException('Every referenced year must be explicitly verified.');
        return 'label:'.$label;
    };
    foreach ($map['sections'] as $section) {
        if (!is_array($section)) throw new InvalidArgumentException('Invalid section mapping.');
        $id=enrollmentMigrationId($section['section_id']??null); $yearRef($section);
        if (isset($sectionIds[$id])) throw new InvalidArgumentException('Duplicate section mapping.');
        $sectionIds[$id]=true;
    }
    foreach ($map['enrollments'] as $enrollment) {
        if (!is_array($enrollment)) throw new InvalidArgumentException('Invalid enrollment mapping.');
        $key=enrollmentMigrationKey($enrollment['key']??null); $student=enrollmentMigrationId($enrollment['student_id']??null);
        $ref=$yearRef($enrollment); [$start,$end]=enrollmentMigrationDates($enrollment);
        if (isset($keys[$key]) || isset($natural[$student.':'.$ref])) throw new InvalidArgumentException('Duplicate enrollment mapping.');
        $keys[$key]=true; $natural[$student.':'.$ref]=true;
        if (!in_array($enrollment['state']??null,['prepared','active','completed'],true)) throw new InvalidArgumentException('Explicit enrollment state is required.');
        if (empty($enrollment['assignments']) || !is_array($enrollment['assignments']) || !array_is_list($enrollment['assignments'])) throw new InvalidArgumentException('Explicit assignments are required.');
        $intervals=[];
        foreach ($enrollment['assignments'] as $assignment) {
            if (!is_array($assignment)) throw new InvalidArgumentException('Invalid assignment mapping.');
            $aKey=enrollmentMigrationKey($assignment['key']??null); enrollmentMigrationId($assignment['section_id']??null);
            if (isset($assignmentKeys[$aKey])) throw new InvalidArgumentException('Duplicate assignment key.');
            $assignmentKeys[$aKey]=true;
            if (!in_array($assignment['grade_level']??null,['Kinder','Grade 1','Grade 2','Grade 3','Grade 4','Grade 5','Grade 6'],true)) throw new InvalidArgumentException('Explicit assignment grade is required.');
            [$aStart,$aEnd]=enrollmentMigrationDates($assignment);
            if ($aStart<$start || $aEnd>$end) throw new InvalidArgumentException('Assignment is outside enrollment dates.');
            foreach ($intervals as [$otherStart,$otherEnd]) if ($aStart<=$otherEnd && $aEnd>=$otherStart) throw new InvalidArgumentException('Assignments overlap.');
            $intervals[]=[$aStart,$aEnd];
        }
    }
    foreach ($map['attendance'] as $attendance) {
        if (!is_array($attendance)) throw new InvalidArgumentException('Invalid attendance mapping.');
        $id=enrollmentMigrationId($attendance['attendance_id']??null); $key=enrollmentMigrationKey($attendance['assignment_key']??null);
        if (isset($attendanceIds[$id]) || !isset($assignmentKeys[$key])) throw new InvalidArgumentException('Attendance mapping duplicates or references an unknown assignment.');
        $attendanceIds[$id]=true;
    }
}

/** Public command owns its transaction. It never rewrites verified rows or enables managed mode. */
function applyEnrollmentMappings(PDO $db, array $actor, array $mapping): array
{
    enrollmentMigrationValidate($mapping);
    $actorId=enrollmentMigrationId($actor['id']??null);
    if ($db->inTransaction()) throw new LogicException('Migration mapping owns its transaction.');
    $db->beginTransaction();
    try {
        if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) throw new DomainException('Enrollment schema is not initialized.');
        $user=$db->prepare('SELECT role,is_active FROM users WHERE id=? LOCK IN SHARE MODE'); $user->execute([$actorId]); $user=$user->fetch();
        if (!$user || $user['role']!=='admin' || !(int)$user['is_active']) throw new DomainException('Migration attribution requires an active administrator.');
        $locked=[];
        foreach (['school_years','sections','students','student_enrollments','enrollment_sections','attendance'] as $table) {
            $locked[$table]=array_column($db->query("SELECT * FROM $table ORDER BY id FOR UPDATE")->fetchAll(PDO::FETCH_ASSOC),null,'id');
        }
        $inventory=inventoryEnrollmentHistory($db);
        if (!hash_equals($inventory['fingerprint'],$mapping['inventory_fingerprint'])) throw new DomainException('Inventory is stale; create a new private inventory.');
        $years=[]; $newYears=[];
        foreach ($mapping['school_years'] as $year) {
            $existing=null;
            if (isset($year['id'])) $existing=$locked['school_years'][(int)$year['id']]??null;
            else foreach ($locked['school_years'] as $candidate) if ($candidate['label']===$year['label']) $existing=$candidate;
            if (isset($year['id']) && !$existing) throw new DomainException('Verified year does not exist.');
            if ($existing && ($existing['label']!==$year['label'] || $existing['starts_on']!==$year['starts_on'] || $existing['ends_on']!==$year['ends_on'])) throw new DomainException('Verified year conflicts with stored boundaries.');
            if (!$existing) {
                foreach (array_merge(array_values($locked['school_years']),$newYears) as $other) {
                    if ($year['starts_on']<=$other['ends_on'] && $year['ends_on']>=$other['starts_on']) throw new DomainException('Verified year dates overlap.');
                }
                $newYears[]=$year;
            }
            $years[$year['label']]=$existing??($year+['id'=>null,'state'=>'planned']);
        }
        $ref=static function(array $row) use (&$years): string {
            if (isset($row['school_year_id'])) foreach ($years as $label=>$year) if ((int)$year['id']===(int)$row['school_year_id']) return $label;
            if (isset($row['school_year_label'],$years[$row['school_year_label']])) return $row['school_year_label'];
            throw new InvalidArgumentException('Unknown verified year.');
        };
        $sectionYears=[];
        foreach ($mapping['sections'] as $section) {
            $id=(int)$section['section_id']; $label=$ref($section); $existing=$locked['sections'][$id]??null;
            if (!$existing || ($existing['school_year_id']!==null && (int)$existing['school_year_id']!==(int)$years[$label]['id'])) throw new DomainException('Section year mapping conflicts.');
            $sectionYears[$id]=$label;
        }
        $planned=[]; $plannedAssignments=[]; $seenNatural=[];
        foreach ($mapping['enrollments'] as $enrollment) {
            $label=$ref($enrollment); $year=$years[$label]; $student=(int)$enrollment['student_id'];
            if (!isset($locked['students'][$student])) throw new DomainException('Enrollment profile is missing.');
            if ($enrollment['starts_on']<$year['starts_on'] || $enrollment['ends_on']>$year['ends_on']) throw new InvalidArgumentException('Enrollment is outside verified year.');
            if (isset($seenNatural[$student.':'.$label])) throw new InvalidArgumentException('Duplicate annual enrollment.');
            $seenNatural[$student.':'.$label]=true; $existing=null;
            foreach ($locked['student_enrollments'] as $candidate) if ((int)$candidate['student_id']===$student && (int)$candidate['school_year_id']===(int)$year['id']) $existing=$candidate;
            if ($existing && ($existing['state']!==$enrollment['state'] || $existing['starts_on']!==$enrollment['starts_on'] || $existing['ends_on']!==$enrollment['ends_on'])) throw new DomainException('Existing enrollment requires the correction flow.');
            if (!$existing && $year['state']==='closed') throw new DomainException('Closed years require the correction flow.');
            $planned[$enrollment['key']]=['input'=>$enrollment,'year_label'=>$label,'existing'=>$existing];
            foreach ($enrollment['assignments'] as $assignment) {
                $section=$locked['sections'][(int)$assignment['section_id']]??null;
                $sectionYear=$sectionYears[(int)$assignment['section_id']]??null;
                if ($section && $sectionYear===null) foreach ($years as $candidateLabel=>$candidateYear) if ((int)$section['school_year_id']===(int)$candidateYear['id']) $sectionYear=$candidateLabel;
                if (!$section || $sectionYear!==$label || $section['grade_level']!==$assignment['grade_level']) throw new DomainException('Assignment section or grade does not match verified year.');
                $existingAssignment=null;
                if ($existing) foreach ($locked['enrollment_sections'] as $candidate) {
                    if ((int)$candidate['enrollment_id']!==(int)$existing['id']) continue;
                    if ($candidate['starts_on']===$assignment['starts_on'] && $candidate['ends_on']===$assignment['ends_on'] && (int)$candidate['section_id']===(int)$assignment['section_id'] && $candidate['grade_level']===$assignment['grade_level']) $existingAssignment=$candidate;
                    elseif ($candidate['starts_on']<=$assignment['ends_on'] && $candidate['ends_on']>=$assignment['starts_on']) throw new DomainException('Existing assignment overlaps; use the correction flow.');
                }
                if (!$existingAssignment && $year['state']==='closed') throw new DomainException('Closed years require the correction flow.');
                $plannedAssignments[$assignment['key']]=['input'=>$assignment,'enrollment_key'=>$enrollment['key'],'student_id'=>$student,'existing'=>$existingAssignment,'year_state'=>$year['state']];
            }
        }
        foreach ($mapping['attendance'] as $entry) {
            $attendance=$locked['attendance'][(int)$entry['attendance_id']]??null; $a=$plannedAssignments[$entry['assignment_key']];
            if (!$attendance || (int)$attendance['student_id']!==$a['student_id'] || $attendance['date']<$a['input']['starts_on'] || $attendance['date']>$a['input']['ends_on']) throw new DomainException('Attendance is outside its explicit owner.');
            if (($attendance['assignment_id']!==null && (int)$attendance['assignment_id']!==(int)($a['existing']['id']??0))
                || ($attendance['enrollment_id']!==null && (int)$attendance['enrollment_id']!==(int)($planned[$a['enrollment_key']]['existing']['id']??0))) throw new DomainException('Attendance is already owned; use the correction flow.');
            if ($a['year_state']==='closed' && $attendance['assignment_id']===null) throw new DomainException('Closed years require the correction flow.');
        }
        // All validation has completed. Resolve local keys only inside this locked transaction.
        securityAuditContext($actorId);
        foreach ($newYears as $year) {
            $db->prepare("INSERT INTO school_years (label,starts_on,ends_on,state) VALUES (?,?,?,'planned')")->execute([$year['label'],$year['starts_on'],$year['ends_on']]);
            $years[$year['label']]['id']=(int)$db->lastInsertId();
            securityAuditChange($db,'enrollment.migration_year','school_year',(int)$db->lastInsertId(),null,$year);
        }
        foreach ($mapping['sections'] as $section) {
            $id=(int)$section['section_id']; $yearId=(int)$years[$sectionYears[$id]]['id'];
            if ($locked['sections'][$id]['school_year_id']===null) {
                $db->prepare('UPDATE sections SET school_year_id=?,updated_at=updated_at WHERE id=?')->execute([$yearId,$id]);
                securityAuditChange($db,'enrollment.migration_section','school_year',$yearId,null,['section_id'=>$id,'school_year_id'=>$yearId]);
            }
        }
        $result=['enrollments'=>[],'assignments'=>[]];
        foreach ($planned as $key=>$entry) {
            $row=$entry['input']; $id=$entry['existing']['id']??null;
            if ($id===null) {
                $db->prepare('INSERT INTO student_enrollments (student_id,school_year_id,state,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$row['student_id'],$years[$entry['year_label']]['id'],$row['state'],$row['starts_on'],$row['ends_on']]);
                $id=(int)$db->lastInsertId(); securityAuditChange($db,'enrollment.migration_enrollment','enrollment',$id,null,$row);
            }
            $result['enrollments'][$key]=(int)$id;
        }
        foreach ($plannedAssignments as $key=>$entry) {
            $row=$entry['input']; $enrollmentId=$result['enrollments'][$entry['enrollment_key']]; $id=$entry['existing']['id']??null;
            if ($id===null) {
                $db->prepare('INSERT INTO enrollment_sections (enrollment_id,section_id,grade_level,starts_on,ends_on) VALUES (?,?,?,?,?)')->execute([$enrollmentId,$row['section_id'],$row['grade_level'],$row['starts_on'],$row['ends_on']]);
                $id=(int)$db->lastInsertId(); securityAuditChange($db,'enrollment.migration_assignment','enrollment',$enrollmentId,null,$row+['assignment_id'=>$id]);
            }
            $result['assignments'][$key]=(int)$id;
        }
        foreach ($mapping['attendance'] as $entry) {
            $id=(int)$entry['attendance_id']; $before=$locked['attendance'][$id]; $assignmentId=$result['assignments'][$entry['assignment_key']];
            $enrollmentId=$result['enrollments'][$plannedAssignments[$entry['assignment_key']]['enrollment_key']];
            if ($before['assignment_id']!==null && $before['enrollment_id']!==null) continue;
            $db->prepare('UPDATE attendance SET enrollment_id=?,assignment_id=?,updated_at=updated_at WHERE id=?')->execute([$enrollmentId,$assignmentId,$id]);
            auditAttendanceAfterWrite($db,$id,$before,'enrollment.migration_attendance');
        }
        $after=inventoryEnrollmentHistory($db); $remaining=[];
        $writeIssue=$db->prepare('INSERT INTO enrollment_migration_issues (record_type,record_id,reason_code) VALUES (?,?,?) ON DUPLICATE KEY UPDATE resolution_type=NULL,resolution_id=NULL,resolved_by=NULL,resolved_at=NULL');
        foreach ($after['issues'] as $issue) {
            $remaining[$issue['record_type'].':'.$issue['record_id'].':'.$issue['code']]=true;
            $writeIssue->execute([$issue['record_type'],$issue['record_id'],$issue['code']]);
        }
        $resolve=$db->prepare("UPDATE enrollment_migration_issues SET resolution_type='explicit_mapping',resolved_by=?,resolved_at=CURRENT_TIMESTAMP WHERE record_type=? AND record_id=? AND reason_code=? AND resolved_at IS NULL");
        foreach ($inventory['issues'] as $issue) if (!isset($remaining[$issue['record_type'].':'.$issue['record_id'].':'.$issue['code']])) {
            $db->prepare('INSERT IGNORE INTO enrollment_migration_issues (record_type,record_id,reason_code) VALUES (?,?,?)')->execute([$issue['record_type'],$issue['record_id'],$issue['code']]);
            $resolve->execute([$actorId,$issue['record_type'],$issue['record_id'],$issue['code']]);
        }
        $db->commit(); return $result;
    } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
}

/** Current gaps block cutover. Historical ambiguities remain available as report qualifications. */
function enrollmentMigrationReadiness(PDO $db, int $yearId): array
{
    $read=$db->prepare('SELECT * FROM school_years WHERE id=?'); $read->execute([$yearId]); $year=$read->fetch(PDO::FETCH_ASSOC);
    if (!$year) throw new DomainException('Readiness year is missing.');
    $inventory=inventoryEnrollmentHistory($db); $source=enrollmentMigrationSources($db);
    $sections=array_column($source['sections'],null,'id'); $attendance=array_column($source['attendance'],null,'id');
    $blocking=[]; $historical=[];
    $add=static function(string $type,int $id,string $code) use (&$blocking): void { $blocking[$type.':'.$id.':'.$code]=['record_type'=>$type,'record_id'=>$id,'code'=>$code]; };
    foreach ($inventory['issues'] as $issue) {
        if ($issue['record_type']==='attendance' && ($attendance[$issue['record_id']]['date']??'')>=$year['starts_on'] && ($attendance[$issue['record_id']]['date']??'')<=$year['ends_on']
            && in_array($issue['code'],['missing_profile','unmapped_attendance','invalid_attendance_ownership'],true)) $add('attendance',$issue['record_id'],$issue['code']);
        else $historical[]=$issue;
    }
    foreach ($sections as $id=>$section) {
        if ((int)$section['is_active'] && $section['school_year_id']===null && ($section['school_year']===$year['label'] || (trim((string)$section['school_year'])==='' && $year['state']==='active'))) $add('section',(int)$id,'unmapped_current_section');
    }
    $query=$db->prepare("SELECT e.id,e.state,e.starts_on,e.ends_on,a.starts_on AS assignment_start,a.ends_on AS assignment_end,sec.school_year_id,sec.is_active,sec.grade_level,a.grade_level AS assignment_grade FROM student_enrollments e LEFT JOIN enrollment_sections a ON a.enrollment_id=e.id LEFT JOIN sections sec ON sec.id=a.section_id WHERE e.student_id=? AND e.school_year_id=? AND e.state IN ('active','prepared') ORDER BY a.starts_on");
    $day=date('Y-m-d'); $day=max($year['starts_on'],min($year['ends_on'],$day));
    foreach ($source['students'] as $student) {
        if (!(int)$student['is_active'] || in_array($student['enrollment_status']??'active',['graduated','transferred_out','dropped'],true)) continue;
        $section=$sections[$student['section_id']??0]??null;
        $query->execute([(int)$student['id'],$yearId]); $rows=$query->fetchAll(PDO::FETCH_ASSOC);
        $relevant=$year['state']==='active' || $rows || ($section && (int)$section['school_year_id']===$yearId) || ($student['school_year']??null)===$year['label'];
        if (!$relevant) continue;
        $eligibleCount=0; $latestEnd=null; $overlap=false;
        foreach ($rows as $row) {
            if ($row['assignment_start']===null) continue;
            if ($latestEnd!==null && $row['assignment_start']<=$latestEnd) $overlap=true;
            $latestEnd=max($latestEnd??$row['assignment_end'],$row['assignment_end']);
            if (($year['state']!=='active' || $row['state']==='active') && (int)$row['school_year_id']===$yearId && (int)$row['is_active'] && $row['grade_level']===$row['assignment_grade'] && $day>=$row['starts_on'] && $day<=$row['ends_on'] && $day>=$row['assignment_start'] && $day<=$row['assignment_end']) $eligibleCount++;
        }
        if ($overlap) $add('student',(int)$student['id'],'overlapping_assignments');
        if ($eligibleCount!==1) $add('student',(int)$student['id'],'missing_current_assignment');
    }
    ksort($blocking,SORT_STRING);
    return ['ready'=>!$blocking,'blocking_issues'=>array_values($blocking),'historical_issues'=>$historical,'fingerprint'=>$inventory['fingerprint']];
}
