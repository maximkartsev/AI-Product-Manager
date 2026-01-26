# Homework 3 prompts:

## Create plan

Create plan of creation/updation of the:
- Migrations
- Resources
- Controllers
- Translations
- Routes

For each part from the documentation folder.

<IMPORTANT> As its already done for RecordController.php and Record.php.
Follow their structure and philosophy in the current project (inheritances, default methods and so on) <IMPORTANT>

The documentation folder: C:\Projects\AI-Product-Management\AI-Product-Manager\homework\2

Tip (v2 workflow): discover the project’s AI-safe automation entry points via:
- `php artisan ai:commands --json`

-------------------------

# Run migration

Run artisan migration by using:
1. docker exec -it bp-workspace-1 bash
2. php artisan migrate

If there will be any issues fix them and rerun migrations

If sharding is enabled and multiple DB connections are configured, prefer:
- `php artisan sharding:migrate`