CREATE TABLE IF NOT EXISTS events (
    uid_event VARCHAR(50) NOT NULL UNIQUE PRIMARY KEY COMMENT 'Unique identifier for the event (e.g., SF230420251320)',
    name VARCHAR(255) NOT NULL COMMENT 'Event name',
    start_date DATETIME NOT NULL COMMENT 'Start date and time in UTC',
    end_date DATETIME NOT NULL COMMENT 'End date and time in UTC',
    address TEXT NOT NULL COMMENT 'Physical address of the event',
    description TEXT COMMENT 'Event description',
    max_attendees INT COMMENT 'Maximum number of attendees allowed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

