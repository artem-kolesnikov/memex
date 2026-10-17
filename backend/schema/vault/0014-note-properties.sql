ALTER TABLE notes ADD COLUMN properties CLOB DEFAULT NULL;
ALTER TABLE deleted_notes ADD COLUMN properties CLOB DEFAULT NULL;
ALTER TABLE note_revisions ADD COLUMN properties CLOB DEFAULT NULL;
ALTER TABLE edit_proposals ADD COLUMN proposed_properties CLOB DEFAULT NULL;
ALTER TABLE edit_proposals ADD COLUMN prev_properties CLOB DEFAULT NULL;
