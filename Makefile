.PHONY: init check-submodules setup-env build-containers create-database install-backend migrate install-frontend

# Default target
.DEFAULT_GOAL := init

# Variables
LARADOCK_DIR := laradock
BACKEND_DIR := backend
FRONTEND_DIR := frontend
COMPOSE_PROJECT_NAME := bp
DB_NAME := bp
DB_USER := root
DB_PASSWORD := root

init: check-submodules setup-env build-containers create-database install-backend migrate install-frontend
	@echo "✅ Initialization complete!"

check-submodules:
	@echo "🔍 Checking git submodules..."
	@if [ ! -d "$(LARADOCK_DIR)" ] || [ -z "$$(ls -A $(LARADOCK_DIR) 2>/dev/null)" ] || [ ! -f "$(LARADOCK_DIR)/docker-compose.yml" ]; then \
		echo "📦 Initializing git submodules..."; \
		git submodule update --init --recursive; \
	else \
		echo "✅ Submodules are already initialized"; \
	fi

setup-env:
	@echo "⚙️  Setting up environment files..."
	@# Copy backend/.env.example to backend/.env if it doesn't exist
	@if [ ! -f "$(BACKEND_DIR)/.env" ]; then \
		echo "📝 Creating $(BACKEND_DIR)/.env from .env.example..."; \
		cp $(BACKEND_DIR)/.env.example $(BACKEND_DIR)/.env; \
	else \
		echo "✅ $(BACKEND_DIR)/.env already exists"; \
	fi
	@# Create or update laradock/.env
	@if [ ! -f "$(LARADOCK_DIR)/.env" ]; then \
		echo "📝 Creating $(LARADOCK_DIR)/.env from .env.example..."; \
		cp $(LARADOCK_DIR)/.env.example $(LARADOCK_DIR)/.env; \
	fi
	@# Update COMPOSE_PROJECT_NAME in laradock/.env
	@echo "🔧 Setting COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME) in $(LARADOCK_DIR)/.env..."
	@if grep -q "^COMPOSE_PROJECT_NAME=" $(LARADOCK_DIR)/.env; then \
		if [ "$$(uname)" = "Darwin" ]; then \
			sed -i '' "s/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME)/" $(LARADOCK_DIR)/.env; \
		else \
			sed -i "s/^COMPOSE_PROJECT_NAME=.*/COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME)/" $(LARADOCK_DIR)/.env; \
		fi; \
	else \
		echo "COMPOSE_PROJECT_NAME=$(COMPOSE_PROJECT_NAME)" >> $(LARADOCK_DIR)/.env; \
	fi
	@echo "✅ Environment files configured"

build-containers:
	@echo "🏗️  Building Docker containers..."
	@cd $(LARADOCK_DIR) && docker compose build workspace php-fpm redis mariadb nginx
	@echo "🚀 Starting Docker containers..."
	@cd $(LARADOCK_DIR) && docker compose up -d workspace php-fpm redis mariadb nginx
	@echo "⏳ Waiting for containers to be ready..."
	@sleep 5
	@echo "✅ Containers are running"

create-database:
	@echo "🗄️  Creating database $(DB_NAME)..."
	@cd $(LARADOCK_DIR) && \
	success=0; \
	for i in 1 2 3 4 5; do \
		if docker compose exec -T mariadb mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS $(DB_NAME) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>/dev/null; then \
			echo "✅ Database $(DB_NAME) created"; \
			success=1; \
			break; \
		fi; \
		echo "⏳ Waiting for mariadb to be ready (attempt $$i/5)..."; \
		sleep 3; \
	done; \
	if [ $$success -eq 0 ]; then \
		echo "❌ Failed to create database after multiple attempts"; \
		exit 1; \
	fi

install-backend:
	@echo "📦 Installing backend dependencies..."
	@cd $(LARADOCK_DIR) && docker compose exec -T workspace bash -c "cd /var/www && composer install"
	@echo "✅ Backend dependencies installed"

migrate:
	@echo "🔄 Running database migrations..."
	@cd $(LARADOCK_DIR) && docker compose exec -T workspace bash -c "cd /var/www && php artisan migrate"
	@echo "✅ Migrations completed"

install-frontend:
	@echo "📦 Installing frontend dependencies..."
	@cd $(FRONTEND_DIR) && pnpm install
	@echo "✅ Frontend dependencies installed"

