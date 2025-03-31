-- drop triggers
DELIMITER $$

DROP TRIGGER IF EXISTS after_user_insert$$
DROP TRIGGER IF EXISTS after_user_update$$
DROP TRIGGER IF EXISTS after_user_delete$$

DELIMITER ;

-- drop user_events table
DROP TABLE IF EXISTS user_events;
