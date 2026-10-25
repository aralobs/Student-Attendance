CREATE TABLE IF NOT EXISTS security_events (
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  request_id CHAR(24) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  actor_id INT NULL,
  device_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NULL,
  action VARCHAR(80) NOT NULL,
  outcome VARCHAR(12) NOT NULL,
  target_type VARCHAR(16) NOT NULL,
  target_id INT NULL,
  alert TINYINT(1) NOT NULL DEFAULT 0,
  route_name VARCHAR(120) NOT NULL,
  occurred_at TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY audit_time (occurred_at), KEY audit_actor (actor_id,occurred_at), KEY audit_alert (alert,occurred_at),
  KEY audit_target (target_type,target_id,occurred_at), KEY audit_request (request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS security_change_history (
  event_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin PRIMARY KEY,
  change_ciphertext MEDIUMTEXT NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
