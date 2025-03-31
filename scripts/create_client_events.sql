-- create user_events table
CREATE TABLE user_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    operation ENUM('INSERT', 'UPDATE', 'DELETE') NOT NULL,
    first_name VARCHAR(255) NOT NULL,
    last_name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    title VARCHAR(50) NOT NULL,
    password VARCHAR(255) NOT NULL,
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
        INSERT INTO user_events (operation, first_name, last_name, email, title, password)
        VALUES ('INSERT', NEW.first_name, NEW.last_name, NEW.email, NEW.custom_1, NEW.pass);
    END IF;
END$$

-- update trigger
CREATE TRIGGER after_user_update
AFTER UPDATE ON client
FOR EACH ROW
BEGIN
    IF COALESCE(@is_consumer_source, 0) <> 1 THEN
        INSERT INTO user_events (operation, first_name, last_name, email, title, password)
        VALUES ('UPDATE', NEW.first_name, NEW.last_name, NEW.email, NEW.custom_1, NEW.pass);
    END IF;
END$$

-- delete trigger
CREATE TRIGGER after_user_delete
AFTER DELETE ON client
FOR EACH ROW
BEGIN
    IF COALESCE(@is_consumer_source, 0) <> 1 THEN
        INSERT INTO user_events (operation, first_name, last_name, email, title, password)
        VALUES ('DELETE', OLD.first_name, OLD.last_name, OLD.email, OLD.custom_1, OLD.pass);
    END IF;
END$$

DELIMITER ;
