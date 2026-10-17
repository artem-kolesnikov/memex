CREATE TABLE note_former_titles (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, note_id INTEGER NOT NULL, title VARCHAR(500) NOT NULL, renamed_at DATETIME NOT NULL);
CREATE INDEX idx_note_former_titles_note ON note_former_titles (note_id);
