Settings › Notes › *Import*, or *Import* on an empty Notes screen. Drop or choose
Markdown (`.md`, `.markdown`) and text (`.txt`) files, or a ZIP of a folder. Each file
becomes one verified note. A ZIP may be up to 32 MB and hold up to two thousand entries, and
what it unpacks to is bounded too, hidden and unsupported files included: 100 MB. A file
larger than the note body limit (2 MB, frontmatter included) is skipped and named; a file
that is not UTF-8, or whose frontmatter uses YAML aliases, runs deeper than 128 levels or
exceeds 64 KB, refuses the whole ZIP before anything is written. (A loose-file upload is
checked file by file instead: the files that fit are saved and each one that does not is
named with its reason.) Folders, hidden files and folders (an Obsidian vault's `.obsidian`,
a `.git` directory) are skipped silently, and anything that is not text is skipped and
named. Nothing else, in particular no PDF and no image, is accepted.
