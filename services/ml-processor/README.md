# memex-ml-processor

AI enrichment service for memex.tools: note summaries, tag suggestions, and embeddings.
Generalized from mm2's production `ml-processor` (2026-08-05) — news-workflow endpoints
(tonality, translation, article analysis, search-query parsing) stripped; the operational
patterns (admin-editable prompts, per-task models, retry wrapper, config gate) kept intact.
Babel dropped — runs on plain Node.

- **Port:** 8201, bound to 127.0.0.1 — only reachable through the nginx proxy / backend.
- **Endpoints:**
  - `POST /api/v1/summarize` `{ content }` → `{ summary }` (plain text, ≤70 words)
  - `POST /api/v1/suggest-tags` `{ title, content, tags: [{id, name}] }` →
    `{ tag_ids: [...], new_tags: [...] }` — existing-vocab matches are name→id mapped
    server-side (models fumble numeric ids); unknown suggestions come back as `new_tags`
  - `POST /api/v1/create-embeddings` `{ content | contents, model, purpose }` → `{ embeddings }`
    — `model` is the vault's vector space, one of two: `text-embedding-3-large` (OpenAI,
    1536 dims, the default) or `nomic-embed-text-v1.5` (run here on the CPU, 768 dims,
    `purpose` `document` or `query`)
  - `GET/PUT /api/v1/config` — prompts + per-task models + API key; requires
    `CONFIG_API_TOKEN` (X-Config-Token header); unset, every call answers 503 and the
    config API is disabled
- **Config:** `config/*.txt`, seeded from the tracked `*.txt.dist` on first deploy; runtime
  edits are never clobbered by deploys (mm2's proven `.dist` pattern). The OpenAI key lives
  in `config/openapi_apikey.txt` — never in git.

## Run

```bash
npm ci
for f in config/*.dist; do cp -n "$f" "${f%.dist}"; done   # seed config once
echo 'sk-...' > config/openapi_apikey.txt                  # add real key
npm start
```

The local model is optional: its runtime is in `optionalDependencies` and its files are
fetched, pinned by revision and checksum, into `models/` (or `LOCAL_EMBEDDING_MODEL_DIR`):

```bash
ONNXRUNTIME_NODE_INSTALL=skip npm ci   # skip the CUDA download onnxruntime-node makes on Linux x64
npm run fetch-model
```

Deployed under systemd (`deploy/systemd/memex-ml-processor.service`).
