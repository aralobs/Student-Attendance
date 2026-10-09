<?php
/** Server-side Calendarific integration; credentials and provider errors stay private. */
function calendarificYear($value): int {
    if (!is_scalar($value) || !preg_match('/^\d{4}$/', (string)$value)
        || (int)$value < 2000 || (int)$value > 2049) {
        throw new InvalidArgumentException('Enter a year between 2000 and 2049.');
    }
    return (int)$value;
}

function calendarificHolidaysFromResponse(string $body, int $status, int $year): array {
    $data = json_decode($body, true);
    $code = $status === 200 ? (int)($data['meta']['code'] ?? 0) : $status;
    $errors = [401 => 'Calendarific rejected the saved API key.',
        403 => 'Your Calendarific subscription is unavailable.',
        429 => 'Calendarific request limit reached. Try again after your quota resets.'];
    if (isset($errors[$code])) throw new InvalidArgumentException($errors[$code]);
    if ($code !== 200 || !is_array($data['response']['holidays'] ?? null)) {
        throw new InvalidArgumentException('Calendarific returned an invalid response. Please try again later.');
    }
    $entries = [];
    foreach ($data['response']['holidays'] as $holiday) {
        $date = $holiday['date']['iso'] ?? null;
        $name = $holiday['name'] ?? null;
        $description = $holiday['description'] ?? '';
        $parsed = is_string($date) ? DateTimeImmutable::createFromFormat('!Y-m-d', $date) : false;
        if (!$parsed || $parsed->format('Y-m-d') !== $date || (int)$parsed->format('Y') !== $year
            || !is_string($name) || trim($name) === '' || !is_string($description)) {
            throw new InvalidArgumentException('Calendarific returned invalid holiday data. Nothing was imported.');
        }
        // The calendar has one entry per date; combine holidays sharing that date.
        $entries[$date]['names'][] = trim($name);
        $entries[$date]['descriptions'][] = $description;
    }
    $result = [];
    foreach ($entries as $date => $entry) {
        $result[] = ['date' => $date,
            'title' => mb_substr(implode(' / ', array_unique($entry['names'])), 0, 100, 'UTF-8'),
            'description' => mb_substr('Imported from Calendarific. ' . implode("\n", array_unique($entry['descriptions'])), 0, 10000, 'UTF-8')];
    }
    return $result;
}

function fetchCalendarificHolidays(string $key, int $year): array {
    if ($key === '') throw new InvalidArgumentException('Save your Calendarific API key first.');
    if (!function_exists('curl_init')) throw new InvalidArgumentException('Enable PHP cURL to connect to Calendarific.');
    $query = http_build_query(['api_key' => $key, 'country' => 'PH', 'year' => $year, 'type' => 'national']);
    $curl = curl_init('https://calendarific.com/api/v2/holidays?' . $query);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25, CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2]);
    $body = curl_exec($curl);
    $status = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    if ($body === false) throw new InvalidArgumentException('Could not connect securely to Calendarific. Please try again.');
    return calendarificHolidaysFromResponse($body, $status, $year);
}

function importCalendarificHolidays(PDO $db, array $holidays, int $userId): array {
    $added = 0;
    $skipped = 0;
    $insert = $db->prepare("INSERT INTO school_calendar (date, title, type, description, created_by)
        VALUES (?, ?, 'holiday', ?, ?)");
    $db->beginTransaction();
    try {
        foreach ($holidays as $holiday) {
            try {
                $insert->execute([$holiday['date'], $holiday['title'], $holiday['description'], $userId]);
                $added++;
            } catch (PDOException $e) {
                // Preserve every existing entry, including manual overrides and seeded dates.
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $skipped++;
            }
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        throw $e;
    }
    return ['added' => $added, 'skipped' => $skipped];
}
