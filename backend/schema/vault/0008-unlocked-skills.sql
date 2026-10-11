CREATE TABLE unlocked_skills (note_id INTEGER NOT NULL PRIMARY KEY, slug VARCHAR(64) NOT NULL, shipped_hash VARCHAR(64) NOT NULL, unlocked_at DATETIME NOT NULL, locked_at DATETIME DEFAULT NULL);
CREATE INDEX idx_unlocked_skills_slug ON unlocked_skills (slug);
