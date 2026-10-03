On this server the *Other* tab also sets up **Claude Desktop**, which starts memex's own
command inside the container rather than calling the address:
`docker exec -i -e MEMEX_TOKEN=YOUR_TOKEN memex php bin/console app:mcp-stdio`. It works on
the computer that runs the container, which has to be named `memex`. Every setup on *Other*
names this memex `memex-local`, so an assistant also connected to memex.tools tells the two
apart.
