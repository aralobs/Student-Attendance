<?php
declare(strict_types=1);

require_once __DIR__ . '/security_audit.php';

/** Labels identify consecutive academic years; dates must be supplied separately. */
function schoolYearLabelStart(string $label): int
{
    if (!preg_match('/\A([1-9][0-9]{3})-([1-9][0-9]{3})\z/', $label, $parts)
        || (int)$parts[2] !== (int)$parts[1] + 1) {
        throw new InvalidArgumentException('School-year label must contain consecutive YYYY-YYYY years.');
    }
    return (int)$parts[1];
}

function nextSchoolYearLabel(string $label): string
{
    $start = schoolYearLabelStart($label) + 1;
    if ($start >= 9999) throw new InvalidArgumentException('Next school-year label exceeds four digits.');
    return $start . '-' . ($start + 1);
}

/** A schema installation alone never switches operational consumers to the model. */
function enrollmentModelEnabled(PDO $db): bool
{
    $read = $db->prepare('SELECT setting_value FROM system_settings WHERE setting_key=?');
    $read->execute(['enrollment_model_version']);
    return $read->fetchColumn() === '1';
}

/** Caller owns the transaction when locking. Structural writers lock control row 1 first. */
function getActiveSchoolYear(PDO $db, bool $lock = false): ?array
{
    if ($lock && !$db->inTransaction()) throw new LogicException('Locking a school year requires a transaction.');
    $years = $db->query("SELECT * FROM school_years WHERE state='active' ORDER BY id" . ($lock ? ' FOR UPDATE' : ''))->fetchAll(PDO::FETCH_ASSOC);
    if (count($years) > 1 || (count($years) === 0 && enrollmentModelEnabled($db))) {
        throw new DomainException('Converted enrollment mode requires exactly one active school year.');
    }
    if (!$years) return null;
    $year = $years[0];
    $year['id'] = (int)$year['id'];
    $year['version'] = (int)$year['version'];
    $year['active_slot'] = (int)$year['active_slot'];
    return $year;
}

function validatedSchoolYearDate($value): string
{
    if (!is_string($value) || !preg_match('/\A([1-9][0-9]{3})-([0-9]{2})-([0-9]{2})\z/', $value, $parts)
        || !checkdate((int)$parts[2], (int)$parts[3], (int)$parts[1])) {
        throw new InvalidArgumentException('School-year boundaries require real ISO dates.');
    }
    return $value;
}

/** Public command owns its transaction; never call inside another command's transaction. */
function savePlannedSchoolYear(PDO $db, array $actor, array $input): int
{
    $actorId = filter_var($actor['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
    if (($actor['role'] ?? null) !== 'admin' || $actorId === false) {
        throw new DomainException('Only an administrator can manage school years.');
    }
    if ($db->inTransaction()) throw new LogicException('School-year save owns its transaction.');
    $label = $input['label'] ?? null;
    if (!is_string($label)) throw new InvalidArgumentException('School-year label is required.');
    schoolYearLabelStart($label);
    $start = validatedSchoolYearDate($input['starts_on'] ?? null);
    $end = validatedSchoolYearDate($input['ends_on'] ?? null);
    if ($start > $end) throw new InvalidArgumentException('School-year start must not follow its end.');
    $id = $input['id'] ?? null;
    $version = $input['expected_version'] ?? null;
    if ($id !== null) {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $version = filter_var($version, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        if ($id === false || $version === false) throw new InvalidArgumentException('Year edits require a valid ID and expected version.');
    } elseif ($version !== null) {
        throw new InvalidArgumentException('New school years do not have an expected version.');
    }

    $db->beginTransaction();
    try {
        // Serialize competing inserts/edits even when the year table is empty.
        if (!$db->query('SELECT id FROM school_year_control WHERE id=1 FOR UPDATE')->fetchColumn()) {
            throw new DomainException('School-year control is not initialized.');
        }
        $years = $db->query('SELECT id,label,starts_on,ends_on,state,version FROM school_years ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
        $activeCount = 0;
        $before = null;
        foreach ($years as $year) {
            $year['id'] = (int)$year['id'];
            $year['version'] = (int)$year['version'];
            if ($year['state'] === 'active') $activeCount++;
            if ($id === $year['id']) {
                $before = $year;
                continue;
            }
            if ($year['label'] === $label) throw new DomainException('School-year label already exists.');
            if ($start <= $year['ends_on'] && $end >= $year['starts_on']) {
                throw new DomainException('School-year dates overlap an existing year.');
            }
        }
        if ($activeCount > 1 || ($activeCount !== 1 && enrollmentModelEnabled($db))) {
            throw new DomainException('Converted enrollment mode requires exactly one active school year.');
        }
        if ($id !== null && (!$before || $before['state'] !== 'planned' || $before['version'] !== $version)) {
            throw new DomainException('School year is missing, no longer planned, or changed since it was opened.');
        }
        if ($id === null) {
            $db->prepare("INSERT INTO school_years (label,starts_on,ends_on,state) VALUES (?,?,?,'planned')")->execute([$label,$start,$end]);
            $id = (int)$db->lastInsertId();
            $nextVersion = 1;
        } else {
            $db->prepare('UPDATE school_years SET label=?,starts_on=?,ends_on=?,version=version+1 WHERE id=?')->execute([$label,$start,$end,$id]);
            $nextVersion = $version + 1;
        }
        $after = ['id'=>$id,'label'=>$label,'starts_on'=>$start,'ends_on'=>$end,'state'=>'planned','version'=>$nextVersion];
        securityAuditContext((int)$actorId);
        securityAuditChange($db, $before === null ? 'school_year.create' : 'school_year.update', 'school_year', $id, $before, $after);
        $db->commit();
        return $id;
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}
