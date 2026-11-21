<?php
declare(strict_types=1);
require_once __DIR__.'/attendance_decisions.php';
require_once __DIR__.'/pii_security.php';

/** Return only a short aggregate category; never send private reason text to charts. */
function topAnalyticsAbsenceReason(array $reasons): ?string {
    $counts=[];
    foreach($reasons as $reason) {
        $reason=trim((string)$reason);
        if($reason==='' || preg_match('/^(?:absent|excused(?: absence)?|unexcused(?: absence)?|no (?:attendance )?scans?|automatically marked absent|auto.?finalized|manual entry)[.!]?$/iu',$reason))continue;
        $category=match(true) {
            (bool)preg_match('/\b(?:sick(?:ness)?|ill(?:ness)?|fever|flu|cough|medical|hospital|dentist|lagnat|masakit)\b|may sakit/iu',$reason)=>'Sickness',
            (bool)preg_match('/\b(?:family|bereavement|funeral|emergency|pamilya)\b/iu',$reason)=>'Family reasons',
            (bool)preg_match('/\b(?:transport(?:ation)?|traffic|fare|pamasahe)\b/iu',$reason)=>'Transportation',
            (bool)preg_match('/\b(?:weather|rain|storm|flood|typhoon|ulan|bagyo)\b/iu',$reason)=>'Weather',
            (bool)preg_match('/\bpersonal\b/iu',$reason)=>'Personal reasons',
            default=>'Other recorded reason',
        };
        $counts[$category]=($counts[$category]??0)+1;
    }
    if(!$counts)return null;
    ksort($counts,SORT_STRING);arsort($counts,SORT_NUMERIC);
    return array_key_first($counts);
}

/** Use exactly the chart's authorized report rows, month, year, and absence status. */
function loadAnalyticsTopAbsenceReason(PDO $db,array $context,int $year,int $month,array $actor): ?string {
    [$scope,$params]=enrollmentSectionScope('reason_sec',$actor,true);
    $hasDecisions=$context['managed'] && panelTableAvailable($db,'attendance_decisions');
    $fields=$hasDecisions?'d.enrollment_id,d.learning_date,d.decision,d.revision,d.reason_ciphertext,':' ';
    $decisionJoin=$hasDecisions?'LEFT JOIN attendance_decisions d ON d.enrollment_id=a.enrollment_id AND d.assignment_id=a.assignment_id AND d.learning_date=a.date':'';
    $q=$db->prepare("SELECT $fields a.remarks FROM {$context['attendance']} a JOIN {$context['students']} s ON a.student_id=s.id {$context['join']}
        JOIN sections reason_sec ON reason_sec.id=s.section_id $decisionJoin
        WHERE YEAR(a.date)=? AND MONTH(a.date)=? AND a.attendance_type='absent' AND s.is_active=1 AND ($scope)");
    $q->execute(array_merge([$year,$month],$params));$reasons=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if($hasDecisions && $row['decision']!==null) {
            // Revised unexcused notes may describe the correction rather than the cause.
            if($row['decision']!=='absent_excused' && (int)$row['revision']>1)continue;
            if($row['reason_ciphertext'])$reasons[]=decryptPII($row['reason_ciphertext'],'attendance-decision:'.$row['enrollment_id'].':'.$row['learning_date']);
        } else $reasons[]=$row['remarks']??'';
    }
    return topAnalyticsAbsenceReason($reasons);
}
