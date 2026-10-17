ALTER TABLE api_tokens ADD COLUMN last_called_at DATETIME DEFAULT NULL;
UPDATE api_tokens SET last_called_at = (
    SELECT MAX(at) FROM (
        SELECT MAX(created_at) AS at FROM curator_log WHERE token_id = api_tokens.id
        UNION ALL
        SELECT MAX(served_at) FROM skill_serves WHERE token_id = api_tokens.id
    )
);
CREATE TABLE connection_usage (token_id INTEGER NOT NULL, day DATE NOT NULL, kind VARCHAR(64) NOT NULL, calls INTEGER DEFAULT 0 NOT NULL, refused INTEGER DEFAULT 0 NOT NULL, failed INTEGER DEFAULT 0 NOT NULL, PRIMARY KEY (token_id, day, kind), CONSTRAINT FK_connection_usage_token FOREIGN KEY (token_id) REFERENCES api_tokens (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE);
CREATE INDEX idx_connection_usage_day ON connection_usage (day);
