# FactoryFlow AI — Laravel starter feature (Phase 1)

This is an **add-on**, not a replacement Laravel application. It is designed to be extracted into `E:\FactoryFlow-AI`, the local copy of `tuckbloor/laravel-mcp-file-server` after removing Git tracking. It does not overwrite `routes/ai.php`, MCP server classes, `composer.json`, or original MCP tools.

## Install in Windows PowerShell

1. Stop Laravel's server on port 8123 for a moment (Ctrl+C).
2. Extract **the contents** of `FactoryFlow-AI-Starter.zip` into `E:\FactoryFlow-AI`. Allow creating new folders/files. There are no existing Laravel files in the ZIP being replaced.
3. In PowerShell:

```powershell
cd E:\FactoryFlow-AI
powershell -ExecutionPolicy Bypass -File .\scripts\install-factoryflow.ps1
php artisan test
php artisan serve --host=127.0.0.1 --port=8123
```

4. Open `http://127.0.0.1:8123/factory`.

If you are already running `php artisan serve` on port 8123 you can keep using that instance; restart if necessary.

## What works in Phase 1

- Vue 3 dashboard with KPIs, production jobs, machine status, controls, alerts, timeline.
- Three.js 3D factory with rotating camera, animated products, 3 belts, machining stations, a moving robot arm, lighting and machine status indicators.
- Functional Laravel JSON API at `/factory/api/status` and `/factory/api/action`.
- Start/pause/resume jobs, start/stop belts, adjust speed, inject/clear faults, emergency stop/release.
- Shared factory state saved in `storage/app/factoryflow/state.json`, guarded by an exclusive process lock.
- Production advances on server status/action requests using elapsed wall time. Closing the UI does not run an independent scheduler; the next request catches up elapsed time. This is sufficient for the visual prototype, not a factory-grade scheduling engine.
- Tests in `tests/Unit/FactorySimulatorTest.php` use isolated temporary directories.

## Boundaries & next stage

- All actions control **simulated** equipment only.
- The REST actions are restricted to loopback and protected by Laravel's CSRF middleware; this app is not ready for internet or LAN hosting. Add authentication before exposing it to others.
- The existing `/mcp` endpoint remains unchanged and retains its original filesystem tools. A **separate factory MCP server** is installed at `/factory/mcp` with `factory-status` and `factory-action` tools. These control only simulated equipment. Always inspect tool changes and keep the endpoint bound to localhost.
- Connect the new server using `codex mcp add factoryflow-controls --url http://127.0.0.1:8123/factory/mcp` from PowerShell and restart Codex. The original `factoryflow` file MCP registration can remain in place.
- The initial state is JSON for easy local setup; migrate to SQLite/MySQL/Eloquent when progressing to production-ready job management and durable production events.
- Sidebar tabs are navigation placeholders in Phase 1; the Factory Floor view contains all working controls and panels.
- The Google Fonts CSS import is optional; if offline, system fonts are used.

## Re-seed the demonstration

With Laravel stopped, delete `storage/app/factoryflow/state.json` and restart. The factory will recreate its three demo jobs and starting equipment states on first request. Do not delete a real data file without a backup once you've added custom jobs.

## Common troubleshooting

- `404 /factory`: verify `require __DIR__.'/factory.php';` was appended to `routes/web.php`; run `php artisan route:list --path=factory`.
- Vite manifest missing: run `npm run build` again.
- Blank dashboard: open developer console; check Vue and Three.js dependencies are installed.
- `419 Page Expired`: refresh the page to get a new CSRF token.
- 3D not rendering: update browser WebGL support / GPU drivers.
- `/mcp` responds 405 to browser GET: expected, unrelated to factory page.
