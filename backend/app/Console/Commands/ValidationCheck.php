<?php

namespace App\Console\Commands;

use App\AI\AgentCommandDefinitionProvider;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class ValidationCheck extends Command implements AgentCommandDefinitionProvider
{
    protected $signature = 'validation:check {--check : Exit non-zero if validation errors are found} {--json : Output machine-readable JSON}';

    protected $description = 'Audit Model::getRules() against DB schema (types, nullability, max lengths) for fillable fields.';

    public static function getAgentCommandDefinition(): array
    {
        return [
            'name' => 'validation:check',
            'category' => 'verification',
            'purpose' => 'Audit Model::getRules() against DB schema for fillable fields (errors + warnings).',
            'usage' => 'php artisan validation:check {--check} {--json}',
            'notes' => [
                'Used by AI OS P10 (validation layers).',
                'In --check mode, fails only on errors (warnings are printed but do not fail).',
                'Run after migrations so DB schema is present.',
            ],
        ];
    }

    public function handle(): int
    {
        $dbName = (string) (config('database.connections.mysql.database') ?? env('DB_DATABASE') ?? '');
        $models = $this->discoverModels();

        $errors = [];
        $warnings = [];

        foreach ($models as $modelClass) {
            /** @var EloquentModel $model */
            $model = new $modelClass();
            $table = $model->getTable();

            if (!Schema::hasTable($table)) {
                $warnings[] = [
                    'model' => $modelClass,
                    'table' => $table,
                    'issue' => 'table_missing',
                    'message' => 'Table does not exist (did you run migrations?).',
                ];
                continue;
            }

            if (!method_exists($modelClass, 'getRules')) {
                continue;
            }

            $rules = $modelClass::getRules();
            if (!is_array($rules)) {
                $errors[] = [
                    'model' => $modelClass,
                    'table' => $table,
                    'issue' => 'rules_not_array',
                    'message' => 'getRules() must return an array.',
                ];
                continue;
            }

            $fillable = $model->getFillable();
            $fkColumns = $this->getForeignKeyColumns($dbName, $table);

            foreach ($fillable as $field) {
                if (!Schema::hasColumn($table, $field)) {
                    $warnings[] = [
                        'model' => $modelClass,
                        'table' => $table,
                        'field' => $field,
                        'issue' => 'fillable_not_a_column',
                        'message' => 'Fillable field is not a DB column.',
                    ];
                    continue;
                }

                if (!array_key_exists($field, $rules)) {
                    $errors[] = [
                        'model' => $modelClass,
                        'table' => $table,
                        'field' => $field,
                        'issue' => 'missing_rule',
                        'message' => 'Missing validation rule for fillable field.',
                    ];
                    continue;
                }

                $ruleStr = is_array($rules[$field]) ? implode('|', $rules[$field]) : (string) $rules[$field];
                $dbType = Schema::getColumnType($table, $field);

                $this->checkType($errors, $modelClass, $table, $field, $dbType, $ruleStr);
                $this->checkMaxLength($warnings, $dbName, $modelClass, $table, $field, $dbType, $ruleStr);

                if (in_array($field, $fkColumns, true) && $field !== (string) config('ownership.user_key', 'user_id')) {
                    if (!str_contains($ruleStr, 'exists:')) {
                        $warnings[] = [
                            'model' => $modelClass,
                            'table' => $table,
                            'field' => $field,
                            'issue' => 'missing_exists',
                            'message' => 'Foreign key column has no exists:* validation.',
                        ];
                    }
                }
            }
        }

        $result = [
            'generated_at' => now()->toIso8601String(),
            'models_scanned' => count($models),
            'errors_count' => count($errors),
            'warnings_count' => count($warnings),
            'errors' => $errors,
            'warnings' => $warnings,
        ];

        if ((bool) $this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info("validation:check — models=" . count($models) . " errors=" . count($errors) . " warnings=" . count($warnings));

            foreach ($errors as $e) {
                $field = $e['field'] ?? '';
                $this->error("ERROR {$e['model']} {$e['table']} {$field}: {$e['message']}");
            }

            foreach ($warnings as $w) {
                $field = $w['field'] ?? '';
                $this->warn("WARN  {$w['model']} {$w['table']} {$field}: {$w['message']}");
            }
        }

        if ((bool) $this->option('check') && !empty($errors)) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @return array<int, class-string<EloquentModel>>
     */
    private function discoverModels(): array
    {
        $modelsDir = app_path('Models');
        if (!is_dir($modelsDir)) {
            return [];
        }

        $models = [];
        foreach (File::allFiles($modelsDir) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $rel = str_replace($modelsDir . DIRECTORY_SEPARATOR, '', $file->getPathname());
            $classPart = str_replace([DIRECTORY_SEPARATOR, '.php'], ['\\', ''], $rel);
            $class = 'App\\Models\\' . $classPart;

            if (!class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, EloquentModel::class)) {
                continue;
            }

            // Skip base infra models that are not validated via getRules().
            if (in_array($class, [
                \App\Models\BaseModel::class,
                \App\Models\User::class,
                \App\Models\OpenAI::class,
            ], true)) {
                continue;
            }

            $models[] = $class;
        }

        sort($models);

        return $models;
    }

    /**
     * @param array<int, array<string, mixed>> $errors
     */
    private function checkType(array &$errors, string $modelClass, string $table, string $field, string $dbType, string $ruleStr): void
    {
        $ok = true;

        if ($dbType === 'json') {
            $ok = str_contains($ruleStr, 'array');
        } elseif ($dbType === 'boolean') {
            $ok = str_contains($ruleStr, 'boolean');
        } elseif ($dbType === 'integer' || $dbType === 'bigint') {
            $ok = str_contains($ruleStr, 'integer') || str_contains($ruleStr, 'numeric');
        } elseif ($dbType === 'decimal' || $dbType === 'float' || $dbType === 'double') {
            $ok = str_contains($ruleStr, 'numeric') || str_contains($ruleStr, 'integer');
        } elseif ($dbType === 'date' || $dbType === 'datetime' || $dbType === 'timestamp') {
            $ok = str_contains($ruleStr, 'date');
        } elseif ($dbType === 'string' || $dbType === 'text') {
            $ok = str_contains($ruleStr, 'string');
        }

        if (!$ok) {
            $errors[] = [
                'model' => $modelClass,
                'table' => $table,
                'field' => $field,
                'issue' => 'type_mismatch',
                'message' => "DB type '{$dbType}' does not match rule '{$ruleStr}'.",
            ];
        }
    }

    /**
     * @param array<int, array<string, mixed>> $warnings
     */
    private function checkMaxLength(array &$warnings, string $dbName, string $modelClass, string $table, string $field, string $dbType, string $ruleStr): void
    {
        if ($dbType !== 'string') {
            return;
        }

        $len = $this->getColumnMaxLength($dbName, $table, $field);
        if (!$len) {
            return;
        }

        if (!preg_match('/\bmax:' . preg_quote((string) $len, '/') . '\b/', $ruleStr)) {
            $warnings[] = [
                'model' => $modelClass,
                'table' => $table,
                'field' => $field,
                'issue' => 'missing_max',
                'message' => "String column length={$len} has no matching max:{$len} rule.",
            ];
        }
    }

    /**
     * @return array<int, string>
     */
    private function getForeignKeyColumns(string $dbName, string $tableName): array
    {
        if ($dbName === '') {
            return [];
        }

        $rows = DB::select(
            "SELECT COLUMN_NAME
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL",
            [$dbName, $tableName]
        );

        $cols = [];
        foreach ($rows as $r) {
            if (!empty($r->COLUMN_NAME)) {
                $cols[] = (string) $r->COLUMN_NAME;
            }
        }

        return array_values(array_unique($cols));
    }

    private function getColumnMaxLength(string $dbName, string $tableName, string $columnName): ?int
    {
        if ($dbName === '') {
            return null;
        }

        $rows = DB::select(
            "SELECT CHARACTER_MAXIMUM_LENGTH
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?",
            [$dbName, $tableName, $columnName]
        );

        if (empty($rows)) {
            return null;
        }

        $v = $rows[0]->CHARACTER_MAXIMUM_LENGTH ?? null;
        if (is_null($v)) {
            return null;
        }

        $len = (int) $v;
        return $len > 0 ? $len : null;
    }
}

