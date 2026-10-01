Settings › AI features has two sections. Each shows what it does and the key it uses, if
any. Neither has a daily limit: what this server does costs nothing, and what a provider
does is billed to your own key.

**Search.** Memex prepares new and edited notes so it can find them by topic as well as
exact words. *Search model* chooses what prepares them. *This server*
(`nomic-embed-text-v1.5`) runs on the server itself, with no key and no cost, and is where
a new memex starts. *OpenAI* (`text-embedding-3-large`) prepares them on your own OpenAI
key, which has to be added first, and OpenAI bills you for it. Changing it prepares every
note again, and until that finishes, search by topic covers only the notes already
prepared; the section counts them. Without its key, an OpenAI search model matches exact
words only, and the section says so. Exact-word search always works.

**Descriptions and tags.** When enabled, Memex writes a description for a saved note that
needs one and adds relevant tags from the existing vocabulary. Supplied descriptions and
chosen tags are kept. New tag names are suggestions only; system tags are excluded. It
needs a personal key from OpenAI, Anthropic or Google: adding one enables it, and the
provider bills you for usage. You can choose a model and turn *Add descriptions and tags
automatically* on or off, saving those changes. A small, fast model is enough. The
editor's title and summary tools use the same key.

**Note maintenance** is in Settings › Assistants (section 17). It runs on your assistant’s
account, not on a provider key stored here.

**Keys.** *Add personal key* asks for a name, the provider and the key. memex makes one
real call to the provider before storing it, so a key that does not work is never saved. It
is stored encrypted and shown afterwards only as its last four characters. *Delete* stops
anything set to run on it: descriptions stop, and an OpenAI search model matches exact
words only until a key is added again or the search model is changed.

**What leaves memex.** With this server's search model, no note text leaves the server to
be prepared. With OpenAI's, note text goes to OpenAI on your key. With descriptions on,
the note's text, title and your tag vocabulary go to the text provider you chose. The
editor's duplicate, link and tag checks run on your own data and send nothing anywhere.
memex does not train on your notes; connected assistants have their own terms.
