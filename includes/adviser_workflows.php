<?php
declare(strict_types=1);
require_once __DIR__.'/absence_interventions.php';
require_once __DIR__.'/teacher_attendance_policy.php';

function adviserWorkflowSchemaSQL(): string {
    return "CREATE TABLE IF NOT EXISTS learner_change_requests (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,student_id INT NOT NULL,section_id INT NOT NULL,
        school_year_id INT NULL,kind VARCHAR(24) NOT NULL,details_ciphertext TEXT NOT NULL,
        requested_by INT NOT NULL,status VARCHAR(16) NOT NULL DEFAULT 'pending',
        review_ciphertext TEXT NULL,reviewed_by INT NULL,created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,KEY request_section(section_id,status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    CREATE TABLE IF NOT EXISTS intervention_followups (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,episode_id BIGINT UNSIGNED NOT NULL,
        outcome VARCHAR(24) NOT NULL,note_ciphertext TEXT NOT NULL,recorded_by INT NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY followup_episode(episode_id,id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
}

function ensureAdviserWorkflowSchema(PDO $db): void { $db->exec(adviserWorkflowSchemaSQL()); }

function requireAdviserWorkflowActor(array $actor, bool $adminOnly=false): void {
    if ((int)($actor['id']??0)<=0 || !in_array($actor['role']??'', $adminOnly?['admin']:['admin','teacher'],true)) throw new DomainException('Access denied.');
}

function adviserWorkflowText(string $text): string {
    $text=trim($text);
    if ($text==='' || strlen($text)>4000 || !mb_check_encoding($text,'UTF-8') || str_contains($text,"\0")) throw new InvalidArgumentException('Enter a note of 1 to 4,000 bytes.');
    return $text;
}

/** Caller owns the transaction. Managed authorization uses structural/current locks. */
function lockAdviserWorkflowLearner(PDO $db, array $actor, int $studentId): array {
    requireAdviserWorkflowActor($actor);
    if (enrollmentModelEnabled($db)) return requireEnrollmentWrite($db,$actor,$studentId,date('Y-m-d'));
    [$scope,$params]=enrollmentSectionScope('sec',$actor);
    $q=$db->prepare("SELECT s.id AS student_id,sec.id AS section_id,NULL AS school_year_id FROM students s JOIN sections sec ON sec.id=s.section_id WHERE s.id=? AND s.is_active=1 AND sec.is_active=1 AND ($scope) FOR UPDATE");
    $q->execute(array_merge([$studentId],$params));
    $row=$q->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new DomainException('Learner access has changed.');
    return $row;
}

function createLearnerChangeRequest(PDO $db, array $actor, int $studentId, string $kind, string $details): int {
    requireAdviserWorkflowActor($actor);
    if (!in_array($kind,['profile','enrollment','attendance'],true)) throw new InvalidArgumentException('Choose a valid request category.');
    $details=adviserWorkflowText($details);
    if ($db->inTransaction()) throw new LogicException('Request owns its transaction.');
    $db->beginTransaction();
    try {
        $ctx=lockAdviserWorkflowLearner($db,$actor,$studentId);
        $cipher=encryptPII($details,'learner-request:'.$studentId);
        $q=$db->prepare('INSERT INTO learner_change_requests(student_id,section_id,school_year_id,kind,details_ciphertext,requested_by) VALUES(?,?,?,?,?,?)');
        $q->execute([$studentId,$ctx['section_id'],$ctx['school_year_id'],$kind,$cipher,(int)$actor['id']]);
        $id=(int)$db->lastInsertId();
        securityAuditContext((int)$actor['id']);
        securityAuditChange($db,'learner.request','student',$studentId,null,['request_id'=>$id,'kind'=>$kind,'requested_by'=>(int)$actor['id']]);
        $db->commit(); return $id;
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function reviewLearnerChangeRequest(PDO $db, array $actor, int $id, string $status, string $note): void {
    requireAdviserWorkflowActor($actor,true);
    if (!in_array($status,['resolved','rejected'],true)) throw new InvalidArgumentException('Choose a review outcome.');
    $note=adviserWorkflowText($note);
    if ($db->inTransaction()) throw new LogicException('Review owns its transaction.');
    $db->beginTransaction();
    try {
        $q=$db->prepare('SELECT * FROM learner_change_requests WHERE id=? FOR UPDATE');$q->execute([$id]);$before=$q->fetch(PDO::FETCH_ASSOC);
        if (!$before || $before['status']!=='pending') throw new DomainException('Request has already been reviewed or is unavailable.');
        $q=$db->prepare('UPDATE learner_change_requests SET status=?,review_ciphertext=?,reviewed_by=?,reviewed_at=NOW() WHERE id=?');
        $q->execute([$status,encryptPII($note,'learner-request-review:'.$id),(int)$actor['id'],$id]);
        securityAuditContext((int)$actor['id']);
        securityAuditChange($db,'learner.request_review','student',(int)$before['student_id'],['request_id'=>$id,'status'=>'pending'],['request_id'=>$id,'status'=>$status,'note'=>$note]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function loadLearnerChangeRequests(PDO $db, array $actor): array {
    requireAdviserWorkflowActor($actor);
    if (!panelTableAvailable($db,'learner_change_requests')) return [];
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare("SELECT r.*,s.first_name,s.last_name,sec.section_name FROM learner_change_requests r JOIN students s ON s.id=r.student_id JOIN sections sec ON sec.id=r.section_id WHERE ($scope) ORDER BY r.id DESC LIMIT 200");
    $q->execute($params);$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) {
        $row['details']=decryptPII($row['details_ciphertext'],'learner-request:'.$row['student_id']);
        $row['review_note']=$row['review_ciphertext']?decryptPII($row['review_ciphertext'],'learner-request-review:'.$row['id']):'';
    }
    return $rows;
}

function interventionFollowupOutcomes(): array {
    return ['contacted'=>'Guardian contacted','scheduled'=>'Meeting scheduled','monitoring'=>'Monitoring','resolved'=>'Follow-up completed','referred'=>'Referred to school head'];
}

function saveInterventionFollowup(PDO $db, array $actor, int $episodeId, string $outcome, string $note): void {
    requireAdviserWorkflowActor($actor);
    if (!isset(interventionFollowupOutcomes()[$outcome])) throw new InvalidArgumentException('Choose a valid follow-up outcome.');
    $note=adviserWorkflowText($note);
    if ($db->inTransaction()) throw new LogicException('Follow-up owns its transaction.');
    $db->beginTransaction();
    try {
        // Resolve structural ownership first, then lock and compare the episode.
        $q=$db->prepare('SELECT ep.*,e.student_id FROM absence_episodes ep JOIN student_enrollments e ON e.id=ep.enrollment_id WHERE ep.id=?');$q->execute([$episodeId]);$candidate=$q->fetch(PDO::FETCH_ASSOC);
        if (!$candidate) throw new DomainException('Intervention is unavailable.');
        $ctx=requireEnrollmentWrite($db,$actor,(int)$candidate['student_id'],$candidate['ends_on']);
        $q=$db->prepare('SELECT * FROM absence_episodes WHERE id=? FOR UPDATE');$q->execute([$episodeId]);$episode=$q->fetch(PDO::FETCH_ASSOC);
        if (!$episode || !(int)$episode['active'] || (int)$ctx['enrollment_id']!==(int)$episode['enrollment_id'] || $episode['ends_on']!==$candidate['ends_on']) throw new DomainException('Intervention changed. Reload before saving.');
        $q=$db->prepare('INSERT INTO intervention_followups(episode_id,outcome,note_ciphertext,recorded_by) VALUES(?,?,?,?)');
        $q->execute([$episodeId,$outcome,encryptPII($note,'intervention-followup:'.$episodeId),(int)$actor['id']]);
        securityAuditContext((int)$actor['id']);
        securityAuditChange($db,'intervention.followup','student',(int)$candidate['student_id'],null,['followup_id'=>(int)$db->lastInsertId(),'episode_id'=>$episodeId,'outcome'=>$outcome,'note'=>$note]);
        $db->commit();
    } catch (Throwable $e) { if ($db->inTransaction()) $db->rollBack(); throw $e; }
}

function loadInterventionFollowups(PDO $db, array $actor, array $episodeIds): array {
    requireAdviserWorkflowActor($actor);
    if (!$episodeIds || !panelTableAvailable($db,'intervention_followups')) return [];
    $ids=array_values(array_unique(array_map('intval',$episodeIds)));
    [$scope,$params]=enrollmentSectionScope('sec',$actor,true);
    $q=$db->prepare('SELECT f.* FROM intervention_followups f JOIN absence_episodes ep ON ep.id=f.episode_id JOIN student_enrollments e ON e.id=ep.enrollment_id JOIN enrollment_sections es ON es.enrollment_id=e.id AND ep.ends_on BETWEEN es.starts_on AND es.ends_on JOIN sections sec ON sec.id=es.section_id AND sec.school_year_id=e.school_year_id WHERE ('.$scope.') AND ep.id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY f.id DESC');
    $q->execute(array_merge($params,$ids));$rows=$q->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$row) $row['note']=decryptPII($row['note_ciphertext'],'intervention-followup:'.$row['episode_id']);
    return $rows;
}
