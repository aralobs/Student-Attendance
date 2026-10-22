CREATE TRIGGER security_events_no_update BEFORE UPDATE ON security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_events_no_delete BEFORE DELETE ON security_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_history_no_update BEFORE UPDATE ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
CREATE TRIGGER security_history_no_delete BEFORE DELETE ON security_change_history FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Security history is append only';
