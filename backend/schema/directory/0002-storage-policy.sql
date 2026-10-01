CREATE TABLE storage_policy (id INTEGER NOT NULL PRIMARY KEY CHECK (id = 1), max_note_bytes INTEGER NOT NULL CHECK (max_note_bytes BETWEEN 1 AND 8388608), max_import_bytes INTEGER NOT NULL CHECK (max_import_bytes BETWEEN 1 AND 1073741824), updated_at DATETIME NOT NULL);
INSERT INTO storage_policy (id, max_note_bytes, max_import_bytes, updated_at) VALUES (1, 2097152, 104857600, CURRENT_TIMESTAMP);
