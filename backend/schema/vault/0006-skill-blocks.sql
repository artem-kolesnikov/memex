CREATE TABLE skill_blocks (note_id INTEGER NOT NULL, token_id INTEGER NOT NULL, PRIMARY KEY (note_id, token_id), CONSTRAINT fk_skill_blocks_note FOREIGN KEY (note_id) REFERENCES notes (id) ON DELETE CASCADE, CONSTRAINT fk_skill_blocks_token FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE);
CREATE INDEX idx_skill_blocks_token ON skill_blocks (token_id);
INSERT INTO skill_blocks (note_id, token_id)
SELECT granted.note_id, k.id
FROM (SELECT DISTINCT g.note_id FROM skill_grants g JOIN api_tokens t ON t.id = g.token_id AND t.revoked_at IS NULL) granted
JOIN api_tokens k ON k.revoked_at IS NULL
WHERE NOT EXISTS (SELECT 1 FROM skill_grants x WHERE x.note_id = granted.note_id AND x.token_id = k.id);
DROP TABLE skill_grants;
