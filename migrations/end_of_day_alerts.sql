ALTER TABLE attendance MODIFY attendance_type ENUM('full_day','partial','absent','holiday','remarked') NOT NULL DEFAULT 'absent';
ALTER TABLE sms_logs MODIFY type ENUM('am_arrival','am_departure','pm_arrival','pm_departure','absence','missing_checkout') NOT NULL;
CREATE TABLE IF NOT EXISTS end_of_day_parent_alerts (
    student_id INT NOT NULL,
    date DATE NOT NULL,
    requested_by INT NOT NULL,
    status ENUM('pending','sent','failed','unknown') NOT NULL DEFAULT 'pending',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
UPDATE attendance SET attendance_type = 'remarked', am_status = NULL, pm_status = NULL
WHERE attendance_type <> 'holiday' AND TRIM(COALESCE(remarks, '')) <> ''
AND am_in IS NULL AND am_out IS NULL AND pm_in IS NULL AND pm_out IS NULL;
