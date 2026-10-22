-- Correct only the three log IDs shown in the 11 October screenshot.
-- No messages are sent; raw provider responses remain unchanged.
SET @previous_sms_time_zone = @@session.time_zone;
SET time_zone = '+08:00';
START TRANSACTION;
UPDATE sms_logs
SET status = 'pending'
WHERE id IN (6, 7, 8)
  AND type = 'missing_checkout'
  AND status = 'failed'
  AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(api_response) THEN api_response ELSE '{}' END,
      '$.message.status')) = 'pending';
UPDATE end_of_day_parent_alerts AS alert
JOIN sms_logs AS log ON log.student_id = alert.student_id
    AND DATE(log.sent_at) = alert.date
SET alert.status = 'pending'
WHERE log.id IN (6, 7, 8)
  AND log.type = 'missing_checkout'
  AND log.status = 'pending'
  AND alert.status = 'failed'
  AND JSON_UNQUOTE(JSON_EXTRACT(
      CASE WHEN JSON_VALID(log.api_response) THEN log.api_response ELSE '{}' END,
      '$.message.status')) = 'pending';
COMMIT;
SET time_zone = @previous_sms_time_zone;
