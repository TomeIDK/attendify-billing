CREATE TABLE client_event_link (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_uid VARCHAR(50) NOT NULL,
    client_id BIGINT NOT NULL,
    registered_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_link (event_uid, client_id),
    CONSTRAINT fk_event FOREIGN KEY (event_uid) REFERENCES events(uid_event) ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_client FOREIGN KEY (client_id) REFERENCES client(id) ON DELETE CASCADE ON UPDATE CASCADE
);