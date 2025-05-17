-- create user_events table
CREATE TABLE user_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    client_id BIGINT NOT NULL,
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    title VARCHAR(50) NOT NULL,
    uid VARCHAR(255) NULL,
    password VARCHAR(255) NOT NULL,
    is_admin VARCHAR(5) NOT NULL DEFAULT 'false',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed BOOLEAN DEFAULT FALSE
);

-- insert trigger
DELIMITER $$

CREATE TRIGGER after_user_insert
AFTER INSERT ON client
FOR EACH ROW
BEGIN
    IF COALESCE(@is_consumer_source, 0) <> 1 THEN
        INSERT INTO user_events (client_id, operation, first_name, last_name, email, title, uid, password, is_admin)
        VALUES (NEW.id, 'INSERT', NEW.first_name, NEW.last_name, NEW.email, NEW.custom_1, NEW.custom_2, NEW.pass, COALESCE(NEW.custom_3, 'false'));
    END IF;
END$$

-- update trigger
CREATE TRIGGER after_user_update
AFTER UPDATE ON client
FOR EACH ROW
BEGIN
    IF COALESCE(@is_consumer_source, 0) <> 1 THEN
        INSERT INTO user_events (client_id, operation, first_name, last_name, email, title, uid, password, is_admin)
        VALUES (NEW.id, 'UPDATE', NEW.first_name, NEW.last_name, NEW.email, NEW.custom_1, NEW.custom_2, NEW.pass, COALESCE(NEW.custom_3, 'false'));
    END IF;
END$$

-- delete trigger
CREATE TRIGGER after_user_delete
AFTER DELETE ON client
FOR EACH ROW
BEGIN
    IF COALESCE(@is_consumer_source, 0) <> 1 THEN
        INSERT INTO user_events (client_id, operation, first_name, last_name, email, title, uid, password, is_admin)
        VALUES (OLD.id, 'DELETE', OLD.first_name, OLD.last_name, OLD.email, OLD.custom_1, OLD.custom_2, OLD.pass, COALESCE(OLD.custom_3, 'false'));
    END IF;
END$$

DELIMITER ;
