# Installation guide

This walkthrough assumes the eventual GitHub repository contains the **actual Laravel project**, not just the documentation kit.

## Prerequisites

- PHP 8.4+ for the locked development install (PHP 8.5.10 verified locally) with Laravel's required PHP extensions (`php -v`)
- Composer (`composer --version`)
- Git (`git --version`)
- Node.js/npm if you want to run MCP Inspector
- OpenAI Codex CLI only if you want to use Codex as your AI client

## Windows (PowerShell)

```powershell
git clone https://github.com/YOUR_USERNAME/laravel-mcp-file-server.git
cd laravel-mcp-file-server
composer install
Copy-Item .env.example .env
php artisan key:generate
New-Item -ItemType Directory -Force storage\app\mcp-files
Set-Content storage\app\mcp-files\example.txt 'Hello from my Laravel MCP server!'
```

Edit `.env` and set:

```dotenv
APP_NAME="Laravel MCP File Server"
APP_ENV=local
APP_DEBUG=false
APP_URL=http://127.0.0.1:8123
```

Start the HTTP server:

```powershell
php artisan serve --host=127.0.0.1 --port=8123
```

Open another terminal:

```powershell
php artisan route:list --path=mcp
curl.exe -i http://127.0.0.1:8123/mcp
```

The ordinary GET should return **405**, with `Allow: POST`. Do not expect to see a webpage at `/mcp`.

## Linux and macOS

```bash
git clone https://github.com/YOUR_USERNAME/laravel-mcp-file-server.git
cd laravel-mcp-file-server
composer install
cp .env.example .env
php artisan key:generate
mkdir -p storage/app/mcp-files
printf 'Hello from my Laravel MCP server!\n' > storage/app/mcp-files/example.txt
php artisan serve --host=127.0.0.1 --port=8123
```

Set the same `.env` properties as above. **Do not bind to a public network interface.**

## Run MCP Inspector

```bash
php artisan mcp:inspector mcp
```

Find the Inspector URL it prints (including its temporary auth token), open it, select **Streamable HTTP** and connect to `http://127.0.0.1:8123/mcp`.

## Run tests

```bash
php artisan test
```

The MCP-specific suite uses isolated temporary fixtures. See RELEASE_VERIFICATION.md for actual results and platform skips.

## Common problems

| Symptom | What to check |
| --- | --- |
| 404 | Make sure port 8123 belongs to this project, not another Laravel app. Run `php artisan route:list --path=mcp`. |
| 405 from a browser or curl GET | Expected for this endpoint. Use an MCP client. |
| Connection refused | Start the correct Laravel instance and confirm its listening port. |
| Inspector says its port is in use | Stop only the stale Inspector instance or set another `CLIENT_PORT`. |
| Tools don't appear | Reconnect and list tools; confirm the tool is registered in `MyMcpServer`. |
| File not found | The folder is `storage/app/mcp-files`, not your project root. |

For client setup and port conflicts, see [CLIENTS.md](CLIENTS.md).

