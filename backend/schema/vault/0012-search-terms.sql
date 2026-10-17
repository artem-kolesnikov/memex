CREATE VIRTUAL TABLE notes_terms USING fts5(title, body_md, content='', contentless_delete=1, tokenize='porter unicode61 remove_diacritics 2');
CREATE TRIGGER notes_terms_insert AFTER INSERT ON notes BEGIN INSERT INTO notes_terms (rowid, title, body_md) VALUES (NEW.id, memex_search_terms(NEW.title), memex_search_terms(NEW.body_md)); END;
CREATE TRIGGER notes_terms_delete AFTER DELETE ON notes BEGIN DELETE FROM notes_terms WHERE rowid = OLD.id; END;
CREATE TRIGGER notes_terms_update AFTER UPDATE OF title, body_md ON notes BEGIN DELETE FROM notes_terms WHERE rowid = OLD.id; INSERT INTO notes_terms (rowid, title, body_md) VALUES (NEW.id, memex_search_terms(NEW.title), memex_search_terms(NEW.body_md)); END;
INSERT INTO notes_terms (rowid, title, body_md) SELECT id, memex_search_terms(title), memex_search_terms(body_md) FROM notes;
