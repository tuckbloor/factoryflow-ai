# Run from E:\FactoryFlow-AI after extracting factoryflow-starter.zip there.
$ErrorActionPreference = 'Stop'
$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $ProjectRoot

if (!(Test-Path 'artisan') -or !(Test-Path 'routes\web.php') -or !(Test-Path 'vite.config.js')) {
    throw 'Not inside an existing Laravel project. Extract the ZIP into E:\FactoryFlow-AI first.'
}

$WebRoutes = Get-Content 'routes\web.php' -Raw
if ($WebRoutes -notmatch "factory\.php") {
    Add-Content 'routes\web.php' "`n// FactoryFlow AI routes`nrequire __DIR__.'/factory.php';`n"
    Write-Host 'Factory routes registered.' -ForegroundColor Green
} else {
    Write-Host 'Factory routes already registered.'
}

$Config = Get-Content 'vite.config.js' -Raw
if ($Config -notmatch '@vitejs/plugin-vue') {
    $Anchor = "import { defineConfig } from 'vite';"
    if (!$Config.Contains($Anchor)) { throw 'Cannot safely patch vite.config.js: missing defineConfig import.' }
    $Config = $Config.Replace($Anchor, $Anchor + "`nimport vue from '@vitejs/plugin-vue';")
}
if ($Config -notmatch 'resources/js/factory.js') {
    $Config = $Config.Replace("'resources/js/app.js'", "'resources/js/app.js', 'resources/js/factory.js'")
}
if ($Config -notmatch 'vue\(\)') {
    $Config = $Config.Replace('plugins: [', "plugins: [`n        vue(),")
}
if ($Config -notmatch 'vue\(\)' -or $Config -notmatch 'resources/js/factory.js') {
    throw 'Could not safely modify vite.config.js; see README for manual setup.'
}
Set-Content 'vite.config.js' $Config -Encoding utf8
Write-Host 'Vite configured for Vue and the factory frontend.' -ForegroundColor Green

$AiRoutes = Get-Content 'routes\ai.php' -Raw
if ($AiRoutes -notmatch 'FactoryMcpServer') {
    Add-Content 'routes\ai.php' "`n// Independent FactoryFlow AI simulation tools; original /mcp remains unchanged.`nMcp::web('/factory/mcp', \App\Mcp\Servers\FactoryMcpServer::class);`n"
    Write-Host 'Factory MCP endpoint registered separately at /factory/mcp.' -ForegroundColor Green
} else {
    Write-Host 'Factory MCP endpoint already registered.'
}

Write-Host 'Installing Vue 3, Three.js and Vite Vue plugin...' -ForegroundColor Cyan
npm install vue three
if ($LASTEXITCODE -ne 0) { throw 'npm install vue three failed.' }
npm install --save-dev '@vitejs/plugin-vue'
if ($LASTEXITCODE -ne 0) { throw 'npm install @vitejs/plugin-vue failed.' }

php artisan optimize:clear
if ($LASTEXITCODE -ne 0) { throw 'Laravel cache clear failed.' }
php artisan route:list --path=factory
if ($LASTEXITCODE -ne 0) { throw 'Factory routes not registered.' }

Write-Host 'Building assets...' -ForegroundColor Cyan
npm run build
if ($LASTEXITCODE -ne 0) { throw 'Frontend build failed.' }
Write-Host 'FactoryFlow AI installed. Go to http://127.0.0.1:8123/factory' -ForegroundColor Green
