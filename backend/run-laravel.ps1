# Laravel Development Server Runner
# This script runs Laravel using Laradock workspace container

Write-Host "Starting Laravel development server..." -ForegroundColor Green
Write-Host "Make sure MariaDB is running: docker-compose -f ../laradock/docker-compose.yml up -d mariadb" -ForegroundColor Yellow
Write-Host ""

$backendPath = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $backendPath

docker run --rm -it `
    -v "${backendPath}:/app" `
    -w /app `
    --network laradock_backend `
    -p 8070:8070 `
    laradock-workspace `
    php artisan serve --host=0.0.0.0 --port=8070
