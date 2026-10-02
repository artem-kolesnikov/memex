**Opening the account.** A memex server holds one account. The first time it is opened in a
browser, it asks for an email address and a password, and creates the account and its
knowledge base. That works for ten minutes after memex first starts. After that, since
anyone who can reach the page could be the one opening it, it also asks for the setup code,
which proves you run the server: memex prints it in its log when it starts
(`docker logs memex`), and `app:setup-code` prints it again. `app:create-owner` creates the
account from a shell instead.

**Signing in** is with that email address and password. Repeated wrong guesses from one
address are refused for a few minutes. memex sends no mail, so a forgotten password is
replaced on the server: `docker exec -it memex php bin/console app:reset-password` sets a
new one and signs out every browser.

**Your email address** is the one given when the account was created. It cannot be changed.

**Deleting the account** returns the server to its first run, with an empty memex and ten
minutes again before the setup code is needed.
