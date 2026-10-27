$ProjectRoot = (Resolve-Path (Join-Path $PSScriptRoot '..')).Path
Set-Location $ProjectRoot
Write-Host 'Starting FactoryFlow AI at http://127.0.0.1:8123/factory' -ForegroundColor Cyan
php artisan serve --host=127.0.0.1 --port=8123
