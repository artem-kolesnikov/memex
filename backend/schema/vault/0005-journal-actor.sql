ALTER TABLE curator_log ADD COLUMN actor VARCHAR(16) DEFAULT 'human' NOT NULL;
UPDATE curator_log SET actor = CASE
    WHEN token_id IS NULL AND token_name = 'operator' THEN 'human'
    WHEN token_id IS NULL AND token_name = 'memex' THEN 'memex'
    WHEN token_id IS NULL AND action IN ('approved', 'rejected', 'flag-raised', 'tag-removed', 'tag-merged') THEN 'human'
    WHEN token_id IS NULL AND action = 'flag-resolved' AND description LIKE 'Withdrew the curation flag%' THEN 'human'
    ELSE 'curator'
END;
ALTER TABLE curator_log ADD COLUMN curation_work BOOLEAN DEFAULT 0 NOT NULL;
UPDATE curator_log SET curation_work = 1;
