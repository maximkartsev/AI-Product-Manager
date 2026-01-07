#!/bin/bash
# Laravel Development Server Runner
# This script runs Laravel using Laradock workspace container

echo "Starting Laravel development server..."
echo "Make sure MariaDB is running: docker-compose -f ../laradock/docker-compose.yml up -d mariadb"
echo ""

BACKEND_PATH="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$BACKEND_PATH/.." && pwd)"

docker run --rm -it \
    -v "${BACKEND_PATH}:/app" \
    -w /app \
    --network laradock_backend \
    -p 8000:8000 \
    laradock-workspace \
    php artisan serve --host=0.0.0.0 --port=8000
