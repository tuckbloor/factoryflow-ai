# Connect an MCP client

The example server uses **Streamable HTTP** at `http://127.0.0.1:8123/mcp` and must be running locally first.

## OpenAI Codex CLI (verified in original manual tests)

After installing and signing in to Codex, run:

```powershell
codex mcp add laravel-files --url http://127.0.0.1:8123/mcp
codex mcp list
codex
```

In the Codex chat, ask:

> List files using `laravel-files`, search for `example.txt` and read it with the MCP read tool. Do not use direct filesystem access or modify files; say which MCP tools were invoked.

Codex may ask whether to approve each call, approve for the session or always allow. For early development, **allow per call or per session**. Client tool confirmation does not substitute for server-side security.

If the server isn't visible in an existing Codex session, start a new Codex session and check `codex mcp list` outside of Codex first.

## Laravel MCP Inspector (verified in original manual tests)

```powershell
php artisan mcp:inspector mcp
```

Use the link printed in the terminal. Click **Connect → Tools → List Tools**, then invoke `list-files-tool`, `search-files-tool` and `read-file-tool` (the exact display name may be kebab-case).

## JetBrains PhpStorm

JetBrains AI Assistant has a built-in **MCP client** in newer PhpStorm versions. The connection is configured under **Tools → AI Assistant → Model Context Protocol (MCP)**. Add an HTTP endpoint with the same URL and check for a connected status. Availability and AI chat features depend on the installed IDE/AI plugin and JetBrains licence; do **not** assume the green MCP connection tick grants access to paid AI agents such as Junie.

The original setup confirmed a **green MCP connection** in PhpStorm; successful AI-driven tool execution was verified with **Codex**, not Junie.

## Port conflicts on Windows

The original tester's port 8000 was occupied by another Laravel application. This project uses 8123 in examples to avoid conflicts. Check:

```powershell
Get-NetTCPConnection -LocalPort 8123 -State Listen |
    Select-Object LocalAddress,LocalPort,OwningProcess
```

Inspect the returned process before stopping it:

```powershell
Get-CimInstance Win32_Process -Filter "ProcessId = YOUR_PID" |
    Select-Object ProcessId,Name,CommandLine
```

If MCP Inspector occupies its default port, start another instance with a different client port **in the same PowerShell session**:

```powershell
$env:CLIENT_PORT = '6384'
$env:MCP_SANDBOX_PORT = '0'
$env:MCP_APP_ORIGIN_PORT = '0'
php artisan mcp:inspector mcp
```

Port 6384 is only an example; use any free local port. Never publish the Inspector's auth token in screenshots or bug reports.

## Security note

Use this server only on the loopback interface, with sample non-sensitive files. External clients, shared-network access, OAuth, reverse proxies, and public hosting need additional design and security review.
