# Project Boilerplate Documentation

## Table of Contents
- [Project Initialization](#project-initialization)
- [Creating Models, Controllers, and Resources](#creating-models-controllers-and-resources)

## Project Initialization

### Prerequisites
- Git
- Docker and Docker Compose
- Make (usually pre-installed on macOS/Linux)
- pnpm (for frontend dependencies)

### Step 1: Clone the Repository with Submodules

This project uses Git submodules (specifically Laradock). When cloning, you need to initialize the submodules:

```bash
git clone <repository-url>
cd project-boilerplate
git submodule update --init --recursive
```

Alternatively, you can clone with submodules in one command:

```bash
git clone --recurse-submodules <repository-url>
cd project-boilerplate
```

### Step 2: Initialize the Project

The project includes a Makefile that automates the entire initialization process. Simply run:

```bash
make init
```

This command will:
1. **Check submodules** - Verify that Laradock submodule is initialized
2. **Setup environment files** - Create `.env` files for backend and Laradock if they don't exist
3. **Build containers** - Build Docker containers (workspace, php-fpm, redis, mariadb, nginx)
4. **Start containers** - Start all required Docker services
5. **Create database** - Create the database (`bp` by default) with proper character set
6. **Install backend dependencies** - Run `composer install` in the workspace container
7. **Run migrations** - Execute Laravel database migrations
8. **Install frontend dependencies** - Run `pnpm install` for frontend packages

### Manual Steps (if needed)

If you prefer to run steps manually or if `make init` fails:

```bash
# 1. Initialize submodules
git submodule update --init --recursive

# 2. Setup environment files
cp backend/.env.example backend/.env
cp laradock/.env.example laradock/.env

# 3. Build and start containers
cd laradock
docker compose build workspace php-fpm redis mariadb nginx
docker compose up -d workspace php-fpm redis mariadb nginx

# 4. Wait for services to be ready, then create database
docker compose exec mariadb mariadb -uroot -proot -e "CREATE DATABASE IF NOT EXISTS bp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 5. Install backend dependencies
docker compose exec workspace bash -c "cd /var/www && composer install"

# 6. Run migrations
docker compose exec workspace bash -c "cd /var/www && php artisan migrate"

# 7. Install frontend dependencies
cd ../frontend
pnpm install
```

## Creating Models, Controllers, and Resources

The project includes a custom Artisan command that automatically generates Models, Controllers, Resources, Routes, and Postman documentation based on your database table structure.

### Command Syntax

```bash
php artisan create:model-controller {table} {entity} [--only=components]
```

### Parameters

- **`table`** (required): The name of the database table
- **`entity`** (required): The entity name (used for model/controller naming)
- **`--only`** (optional): Comma-separated list of components to create. If omitted, all components are created.

### Available Components

- `model` - Creates an Eloquent model extending `BaseModel`
- `controller` - Creates a RESTful controller extending `BaseController`
- `resource` - Creates a JSON API resource
- `route` - Adds resource routes to `routes/api.php`
- `doc` - Generates Postman collection documentation
- `translations` - (Currently commented out) Generates translation files

### Examples

#### Create All Components (Default)

```bash
php artisan create:model-controller users user
```

This will create:
- `App\Models\User` model with fillable fields, casts, validation rules, and `belongsTo` relationships
- `App\Http\Controllers\UserController` with full CRUD operations
- `App\Http\Resources\User` resource
- Resource routes in `routes/api.php`
- Postman documentation (if API keys are configured)

#### Create Only Model and Controller

```bash
php artisan create:model-controller products product --only=model,controller
```

#### Create Only Routes

```bash
php artisan create:model-controller orders order --only=route
```

#### Create Model, Controller, and Resource (without routes and docs)

```bash
php artisan create:model-controller categories category --only=model,controller,resource
```

### What Gets Generated

#### Model Features

- **Fillable fields**: Automatically extracted from table columns (excludes `id`, `created_at`, `updated_at`)
- **Casts**: Automatically generated for integer, float, double, and decimal columns
- **Validation rules**: Generated `getRules()` static method with:
  - Type validation (numeric, boolean, date, datetime, string)
  - Nullable/required rules based on database schema
  - Foreign key validation using `exists` rule
- **Relationships**: Automatically generates `belongsTo` relationships for columns ending with `_id`

#### Controller Features

- **Full CRUD operations**:
  - `index()` - List with pagination, search, filtering, and sorting
  - `store()` - Create new resource
  - `show($id)` - Get single resource
  - `create()` - Get form data for creating
  - `update($id)` - Update existing resource
  - `destroy($id)` - Delete resource
- **Automatic relationship loading**: Eager loads related models if foreign keys are detected
- **Search functionality**: Searchable fields automatically configured
- **Filtering**: Advanced filtering support through `BaseController`

#### Resource Features

- Basic JSON resource structure ready for customization
- Extends `Illuminate\Http\Resources\Json\JsonResource`

#### Route Features

- Adds resource routes to `routes/api.php`
- Uses `auth:sanctum` middleware
- Excludes `edit` route (not needed for API)
- Automatically adds `use` statement for the controller

#### Postman Documentation

- Generates Postman collection with all CRUD endpoints
- Requires `POSTMAN_COLLECTION_ID` and `POSTMAN_API_KEY` in `.env`
- Creates a folder in your Postman collection with sample requests

### Running the Command in Docker

Since the project runs in Docker containers, execute the command inside the workspace container:

```bash
cd laradock
docker compose exec workspace bash -c "cd /var/www && php artisan create:model-controller {table} {entity} [--only=components]"
```

Or if you're already in the laradock directory:

```bash
docker compose exec workspace bash -c "cd /var/www && php artisan create:model-controller {table} {entity} [--only=components]"
```

### Example Workflow

1. **Create a migration** for your new table:
   ```bash
   php artisan make:migration create_products_table
   ```

2. **Run the migration**:
   ```bash
   php artisan migrate
   ```

3. **Generate model, controller, and routes**:
   ```bash
   php artisan create:model-controller products product
   ```

4. **Customize the generated files** as needed (especially the Resource class for API responses)

### Notes

- The command analyzes your database table structure to generate appropriate code
- Foreign key columns (ending with `_id`) automatically generate `belongsTo` relationships
- The command uses `BaseModel` and `BaseController` which provide additional functionality
- Generated controllers include comprehensive error handling and validation
- Routes are automatically added to `routes/api.php` with proper middleware

