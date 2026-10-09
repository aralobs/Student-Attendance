<?php
// Isolated provider-response and import checks; no database or network calls.
require_once __DIR__ . '/../includes/calendarific.php';
function check(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function rejects(callable $operation): void {
    try { $operation(); } catch (InvalidArgumentException $e) { return; }
    throw new RuntimeException('Invalid input was accepted.');
}
function response(array $holidays): string {
    return json_encode(['meta' => ['code' => 200], 'response' => ['holidays' => $holidays]]);
}
$holiday = ['date' => ['iso' => '2027-01-01'], 'name' => "New Year's Day", 'description' => 'National holiday'];
$entries = calendarificHolidaysFromResponse(response([$holiday]), 200, 2027);
check(count($entries) === 1 && $entries[0]['date'] === '2027-01-01', 'Holiday date changed.');
$second = $holiday;
$second['name'] = 'Another holiday';
check(count(calendarificHolidaysFromResponse(response([$holiday, $second]), 200, 2027)) === 1, 'Same-date holidays were not combined.');
$second['name'] = str_repeat('日', 120);
check(mb_strlen(calendarificHolidaysFromResponse(response([$second]), 200, 2027)[0]['title']) === 100, 'Unicode title length exceeded the schema.');
check(calendarificHolidaysFromResponse(response([]), 200, 2027) === [], 'Empty result failed.');
foreach ([401, 403, 429, 500, 503] as $status) rejects(fn() => calendarificHolidaysFromResponse('secret provider detail', $status, 2027));
rejects(fn() => calendarificHolidaysFromResponse('not JSON', 200, 2027));
rejects(fn() => calendarificHolidaysFromResponse(response([$holiday]), 200, 2026));
$bad = $holiday;
$bad['date']['iso'] = '2027-02-30';
rejects(fn() => calendarificHolidaysFromResponse(response([$holiday, $bad]), 200, 2027));
rejects(fn() => calendarificHolidaysFromResponse(response([['name' => []]]), 200, 2027));
foreach ([[], null, '', '2027x', '1999', '2050'] as $badYear) rejects(fn() => calendarificYear($badYear));
check(calendarificYear('2027') === 2027, 'Valid year rejected.');

class ImportDatabase extends PDO {
    public array $rows = ['2027-01-01' => 'Manual school day'];
    public array $snapshot = [];
    public bool $active = false;
    public bool $fail = false;
    public function __construct() {}
    public function prepare(string $query, array $options = []): PDOStatement|false { return new ImportStatement($this); }
    public function beginTransaction(): bool { $this->snapshot = $this->rows; $this->active = true; return true; }
    public function commit(): bool { $this->active = false; return true; }
    public function rollBack(): bool { $this->rows = $this->snapshot; $this->active = false; return true; }
    public function inTransaction(): bool { return $this->active; }
}
class ImportStatement extends PDOStatement {
    private ImportDatabase $db;
    public function __construct(ImportDatabase $db) { $this->db = $db; }
    public function execute(?array $params = null): bool {
        if (isset($this->db->rows[$params[0]]) || ($this->db->fail && $params[0] === '2027-12-30')) {
            $exception = new PDOException('Simulated database failure');
            $exception->errorInfo = ['23000', isset($this->db->rows[$params[0]]) ? 1062 : 1452, 'failure'];
            throw $exception;
        }
        $this->db->rows[$params[0]] = $params[1];
        return true;
    }
}
$db = new ImportDatabase();
$missing = ['date' => '2027-12-25', 'title' => 'Christmas Day', 'description' => 'Imported'];
$counts = importCalendarificHolidays($db, [$entries[0], $missing], 1);
check($counts === ['added' => 1, 'skipped' => 1], 'Import counts are wrong.');
check($db->rows['2027-01-01'] === 'Manual school day', 'Existing manual entry was overwritten.');
check(importCalendarificHolidays($db, [$missing], 1) === ['added' => 0, 'skipped' => 1], 'Repeated import duplicated a date.');
$db = new ImportDatabase();
$db->fail = true;
$failed = false;
try {
    importCalendarificHolidays($db, [$missing, ['date' => '2027-12-30', 'title' => 'Rizal Day', 'description' => 'Imported']], 1);
} catch (PDOException $e) { $failed = true; }
check($failed && $db->rows === ['2027-01-01' => 'Manual school day'] && !$db->active, 'Failed import was not rolled back.');
echo "Calendarific checks passed.\n";
