CREATE TABLE IF NOT EXISTS sf2_remarks (
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    report_month TINYINT UNSIGNED NOT NULL,
    report_year SMALLINT UNSIGNED NOT NULL,
    remark_ciphertext TEXT NOT NULL,
    version INT UNSIGNED NOT NULL,
    updated_by INT NOT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (student_id, section_id, report_month, report_year)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS sf2_remark_history (
    event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY,
    student_id INT NOT NULL,
    section_id INT NOT NULL,
    report_month TINYINT UNSIGNED NOT NULL,
    report_year SMALLINT UNSIGNED NOT NULL,
    actor_id INT NOT NULL,
    version INT UNSIGNED NOT NULL,
    change_ciphertext TEXT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX sf2_history_target (student_id, section_id, report_year, report_month)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
