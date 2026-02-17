<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use GuzzleHttp\Client;
use Mockery\Exception;

class CreateModelAndController extends Command
{
    protected $signature = 'create:model-controller {table : Table name} {entity : Entity name} {--only= : Specify what to create: model,controller,resource,doc,route} {--scope=auto : Scope: auto|tenant|central (default auto)}';

    protected $description = 'Create model and controller based on given table structure';

    public function handle()
    {
        $table = $this->argument('table');
        $entity = $this->argument('entity');
        $only = $this->option('only');
        $scopeOpt = strtolower(trim((string) ($this->option('scope') ?? 'auto')));
        if ($scopeOpt === '') {
            $scopeOpt = 'auto';
        }

        if (!in_array($scopeOpt, ['auto', 'tenant', 'central'], true)) {
            $this->warn("Invalid --scope value '{$scopeOpt}'. Valid: auto|tenant|central. Falling back to auto.");
            $scopeOpt = 'auto';
        }

        // Parse the --only option
        $createComponents = $this->parseOnlyOption($only);

        [$schemaConnection, $isTenantScoped] = $this->resolveSchemaConnectionAndScope($table, $scopeOpt);

        $model = ucwords(Str::camel(Str::singular($entity)));
        $controllerName = $model . 'Controller';

        $fillable = $this->getColumns($schemaConnection, $table)->reject(function ($column) use ($isTenantScoped) {
            if (in_array($column, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
                return true;
            }
            if ($isTenantScoped && $column === 'tenant_id') {
                return true;
            }
            return false;
        })->toArray();

        $relations = $this->getRelationsByFillable($fillable);
        $hasRelations = !empty($relations);
        $hasSlug = Schema::connection($schemaConnection)->hasColumn($table, 'slug');

        $controller = $model . 'Controller';
        $resource = $model;
        $modelNamespace = 'App\\Models';
        $controllerNamespace = 'App\\Http\\Controllers';
        $resourceNamespace = 'App\\Http\\Resources';

        $modelFileName = $model . '.php';
        $controllerFileName = $controller . '.php';
        $resourceFileName = $resource . '.php';

        $modelPath = app_path('Models/' . $modelFileName);
        $resourcePath = app_path('Http/Resources/' . $resourceFileName);
        $controllerPath = app_path('Http/Controllers/' . $controllerFileName);

        // Create model if specified
        if ($createComponents['model']) {
            $baseModel = $isTenantScoped ? 'TenantModel' : 'CentralModel';
            $hasSoftDeletes = Schema::connection($schemaConnection)->hasColumn($table, 'deleted_at');

            // Generate model file
            Artisan::call('make:model', [
                'name' => $model,
            ]);

            $modelContent = file_get_contents($modelPath);

            $fillableContent = "\n\n protected ".'$fillable=['."\n'".implode("',\n'",$fillable)."\n\n". "\n".'];'."\n\n";

            $castsContent = $this->generateCasts($schemaConnection, $table, $isTenantScoped);

            $rulesContent = $this->generateGetRulesFunction($schemaConnection, $table, $isTenantScoped);

            $belongsToRelations = collect($fillable)->filter(function ($column) {
                return Str::endsWith($column, '_id') && $column !== 'tenant_id';
            })->map(function ($column) {
                $relatedModel = ucfirst(Str::camel(str_replace('_id', '', Str::snake($column))));
                $methodName = lcfirst($relatedModel);
                $fqcn = "\\App\\Models\\{$relatedModel}";

                if (class_exists($fqcn)) {
                    return "public function {$methodName}() {\n    return \$this->belongsTo({$fqcn}::class);\n}";
                }

                return "// public function {$methodName}() {\n//     return \$this->belongsTo({$fqcn}::class);\n// }\n// TODO: Generate {$fqcn} to enable {$methodName}() relation.";
            })->implode("\n\n");

            $modelContent = str_replace('extends Model', "extends {$baseModel}", $modelContent);

            if ($hasSoftDeletes && !str_contains($modelContent, 'Illuminate\\Database\\Eloquent\\SoftDeletes')) {
                $modelContent = str_replace(
                    "namespace App\\Models;\n\n",
                    "namespace App\\Models;\n\nuse Illuminate\\Database\\Eloquent\\SoftDeletes;\n\n",
                    $modelContent,
                );
            }

            $classPreamble = '';
            if ($hasSoftDeletes) {
                $classPreamble .= "use SoftDeletes;\n\n";
            }
            if (!$isTenantScoped) {
                $classPreamble .= "public bool \$enableLoggingModelsEvents = false;\n\n";
            }

            $modelContent = str_replace(
                "extends {$baseModel}\n{",
                "extends {$baseModel}\n{\n\n" . $classPreamble . $fillableContent . $castsContent . $rulesContent . $belongsToRelations,
                $modelContent,
            );

            $modelContent = preg_replace_callback('/(protected \$fillable\s*=\s*\[)(.*?)(\];)/sm', function ($matches) use ($fillable) {
                $fillables = collect($fillable)->map(function ($column) {
                    return "        '{$column}',";
                })->implode("\n");

                return "{$matches[1]}\n{$fillables}    {$matches[3]}";
            }, $modelContent);

            file_put_contents($modelPath, $modelContent);
            $this->info("Model {$model} created successfully.");
        }

        // Create resource if specified
        if ($createComponents['resource']) {
            $resourceContent = $this->generateResourceContent($model);
            file_put_contents($resourcePath, $resourceContent);
            $this->info("Resource {$resource} created successfully.");
        }

        // Create controller if specified
        if ($createComponents['controller']) {
            $controllerContent = $this->generateControllerContent($model, $fillable, $hasRelations, $hasSlug);
            file_put_contents($controllerPath, $controllerContent);
            $this->info("Controller {$controller} created successfully.");
        }

        // Generate translations if any component was created
        if ($createComponents['translations']) {
            Artisan::call('translations:scan');
        }

        // Add route if specified
        if ($createComponents['route']) {
            $this->appendResourceRoute($controller, $isTenantScoped);
            $this->info("Route for {$controller} added successfully.");
        }

        // Generate Postman documentation if specified
        if ($createComponents['doc']) {
            $this->generatePostmanDocumentation($controller, $fillable);
        }
    }

    /**
     * Parse the --only option and return what components should be created
     *
     * @param string|null $only
     * @return array
     */
    private function parseOnlyOption($only): array
    {
        $defaultComponents = [
            'model' => true,
            'controller' => true,
            'resource' => true,
            'doc' => true,
            'route' => true,
            'translations' => true,
        ];

        if (empty($only)) {
            return $defaultComponents;
        }

        $requestedComponents = array_map('trim', explode(',', $only));
        $validComponents = ['model', 'controller', 'resource', 'doc', 'route','translations'];

        $components = [
            'model' => false,
            'controller' => false,
            'resource' => false,
            'doc' => false,
            'route' => false,
            'translations' => false,
        ];

        foreach ($requestedComponents as $component) {
            if (in_array($component, $validComponents)) {
                $components[$component] = true;
            } else {
                $this->warn("Invalid component '{$component}' specified. Valid components are: " . implode(', ', $validComponents));
            }
        }

        return $components;
    }

    /**
     * Resolve which DB connection to inspect for the table and whether the table is tenant-scoped.
     *
     * @return array{0: string, 1: bool} [schemaConnection, isTenantScoped]
     */
    private function resolveSchemaConnectionAndScope(string $table, string $scopeOpt): array
    {
        $centralHas = Schema::connection('central')->hasTable($table);
        $tenantHas = Schema::connection('tenant')->hasTable($table);

        if ($scopeOpt === 'central') {
            if (!$centralHas) {
                $this->error("Table '{$table}' not found on central connection.");
                throw new \RuntimeException('Table not found on central connection.');
            }
            return ['central', false];
        }

        if ($scopeOpt === 'tenant') {
            if (!$tenantHas) {
                $this->error("Table '{$table}' not found on tenant connection.");
                throw new \RuntimeException('Table not found on tenant connection.');
            }
            return ['tenant', true];
        }

        // auto: pick the connection where the table exists (prefer central if present).
        if ($centralHas) {
            $conn = 'central';
        } elseif ($tenantHas) {
            $conn = 'tenant';
        } else {
            $this->error("Table '{$table}' not found on central or tenant connections.");
            throw new \RuntimeException('Table not found on configured connections.');
        }

        $isTenantScoped = Schema::connection($conn)->hasColumn($table, 'tenant_id');

        return [$conn, $isTenantScoped];
    }

    private function generateResourceContent(string $modelName): string
    {

        $resourceName = $modelName;

        $resource = "<?php\n\n";
        $resource .= "namespace App\Http\Resources;\n\n";
        $resource .= "use Illuminate\Http\Resources\Json\JsonResource;\n\n";
        $resource .= "class $resourceName extends JsonResource\n";
        $resource .= "{\n";
        $resource .= "    /**\n";
        $resource .= "     * Transform the resource into an array.\n";
        $resource .= "     *\n";
        $resource .= "     * @param  \Illuminate\Http\Request  \$request\n";
        $resource .= "     * @return array\n";
        $resource .= "     */\n";
        $resource .= "    public function toArray(\$request)\n";
        $resource .= "    {\n";
        $resource .= "        return parent::toArray(\$request); \n";
        $resource .= "    }\n";
        $resource .= "}\n";

        return $resource;
    }

    private function getDatabaseNameForConnection(string $connection): string
    {
        $db = (string) (config("database.connections.{$connection}.database") ?? '');
        if ($db !== '') {
            return $db;
        }

        // Fallbacks (useful in some CLI contexts)
        return (string) (env('DB_DATABASE') ?? '');
    }

    /**
     * @return array<int, object{COLUMN_NAME:string,DATA_TYPE:string,COLUMN_TYPE:string,IS_NULLABLE:string,CHARACTER_MAXIMUM_LENGTH:?int,COLUMN_KEY:string}>
     */
    private function getTableColumnsInfo(string $connection, string $tableName): array
    {
        $dbName = $this->getDatabaseNameForConnection($connection);
        if ($dbName === '') {
            throw new \RuntimeException("Unable to resolve database name for connection '{$connection}'.");
        }

        /** @var array<int, object> $rows */
        $rows = DB::connection($connection)->select(
            "SELECT COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH, COLUMN_KEY
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL_POSITION",
            [$dbName, $tableName],
        );

        return $rows;
    }

    protected function generateGetRulesFunction(string $connection, string $tableName, bool $isTenantScoped)
    {
        $rules = collect();

        $columnsInfo = $this->getTableColumnsInfo($connection, $tableName);
        $dbName = $this->getDatabaseNameForConnection($connection);
        $foreignKeys = $this->getTableForeignKeys($connection, $dbName, $tableName);

        foreach ($columnsInfo as $col) {
            $columnName = (string) $col->COLUMN_NAME;

            // Skip system-managed fields
            if (in_array($columnName, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if ((string) $col->COLUMN_KEY === 'PRI') {
                continue;
            }
            if ($columnName === 'deleted_at') {
                continue;
            }
            if ($isTenantScoped && $columnName === 'tenant_id') {
                continue;
            }

            $dataType = strtolower((string) $col->DATA_TYPE);
            $columnType = strtolower((string) $col->COLUMN_TYPE);
            $maxLen = $col->CHARACTER_MAXIMUM_LENGTH !== null ? (int) $col->CHARACTER_MAXIMUM_LENGTH : null;
            $nullable = strtoupper((string) $col->IS_NULLABLE) === 'YES';

            $columnRules = collect();

            // Type mapping (MySQL via INFORMATION_SCHEMA)
            if (($dataType === 'tinyint' && $columnType === 'tinyint(1)') || $dataType === 'boolean') {
                $columnRules->push('boolean');
            } elseif (in_array($dataType, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
                $columnRules->push('numeric');
            } elseif (in_array($dataType, ['decimal', 'numeric', 'float', 'double', 'real'], true)) {
                $columnRules->push('numeric');
            } elseif ($dataType === 'date') {
                $columnRules->push('date');
            } elseif (in_array($dataType, ['datetime', 'timestamp'], true)) {
                $columnRules->push('date_format:Y-m-d H:i:s');
            } else {
                $columnRules->push('string');
                if ($maxLen !== null && in_array($dataType, ['varchar', 'char'], true)) {
                    $columnRules->push('max:' . $maxLen);
                }
            }

            if ($nullable) {
                $columnRules->push('nullable');
            } else {
                $columnRules->push('required');
            }

            // If column is a foreign key, add an 'exists' validation rule
            if (array_key_exists($columnName, $foreignKeys)) {
                $relatedTable = $foreignKeys[$columnName]['table'];
                $columnRules->push("exists:$relatedTable,id");
            }

            $rules->put($columnName, $columnRules->implode('|'));
        }

        $function = "public static function getRules(\$id = null)\n{\n    return [\n";

        foreach ($rules as $column => $rule) {
            $function .= "        '{$column}' => '{$rule}',\n";
        }

        $function .= "    ];\n}";

        return $function;
    }

    protected function generateCasts(string $connection, string $tableName, bool $isTenantScoped)
    {
        $casts = [];

        $columnsInfo = $this->getTableColumnsInfo($connection, $tableName);

        foreach ($columnsInfo as $col) {
            $columnName = (string) $col->COLUMN_NAME;

            if (in_array($columnName, ['created_at', 'updated_at'], true)) {
                continue;
            }
            if ((string) $col->COLUMN_KEY === 'PRI') {
                continue;
            }
            if ($columnName === 'deleted_at') {
                continue;
            }
            if ($isTenantScoped && $columnName === 'tenant_id') {
                continue;
            }

            if (str_ends_with($columnName, '_id')) {
                continue;
            }

            $dataType = strtolower((string) $col->DATA_TYPE);
            $columnType = strtolower((string) $col->COLUMN_TYPE);

            if (($dataType === 'tinyint' && $columnType === 'tinyint(1)') || $dataType === 'boolean') {
                $casts[$columnName] = 'boolean';
                continue;
            }

            if (in_array($dataType, ['int', 'integer', 'bigint', 'smallint', 'mediumint', 'tinyint'], true)) {
                $casts[$columnName] = 'int';
                continue;
            }

            if (in_array($dataType, ['decimal', 'numeric', 'float', 'double', 'real'], true)) {
                $casts[$columnName] = 'float';
                continue;
            }
        }

        $content = "protected \$casts = [\n";

        foreach ($casts as $column => $type) {
            $content .= "        '{$column}' => '{$type}',\n";
        }

        $content .= "    ];\n";

        return $content."\n";
    }

    private function getColumns(string $connection, string $table)
    {
        $fields = new Collection();

        $columns = DB::connection($connection)->select("SHOW COLUMNS FROM $table");

        foreach ($columns as $column) {
            if ($column->Extra != 'auto_increment') {
                $fields->add($column->Field);
            }
        }

        return $fields;
    }

    private function getRelationsByFillable($fillable)
    {
        $relations = collect($fillable)->filter(function ($column) {
            return Str::endsWith($column, '_id');
        })->map(function ($column) {
            return lcfirst(Str::camel(str_replace('_id', '', Str::snake($column))));
        })->toArray();


        return $relations;
    }

    /**
     * @param array<int, string> $fillable
     * @return array{valid: array<int, string>, missing: array<int, array{method: string, fqcn: string}>}
     */
    private function getRelationsInfoByFillable(array $fillable): array
    {
        $valid = [];
        $missing = [];

        foreach ($fillable as $column) {
            if (!Str::endsWith($column, '_id') || $column === 'tenant_id') {
                continue;
            }

            $relatedModel = ucfirst(Str::camel(str_replace('_id', '', Str::snake($column))));
            $methodName = lcfirst($relatedModel);
            $fqcn = "\\App\\Models\\{$relatedModel}";

            if (class_exists($fqcn)) {
                $valid[] = $methodName;
            } else {
                $missing[] = [
                    'method' => $methodName,
                    'fqcn' => $fqcn,
                ];
            }
        }

        return [
            'valid' => $valid,
            'missing' => $missing,
        ];
    }

    /**
     * Build an eager-load snippet that is safe by default:
     * - loads only valid relations
     * - emits commented lines for missing relations
     *
     * The returned string is designed to be inserted inside a heredoc where the first line is already indented.
     *
     * @param string $varName Example: '$item' or '$items'
     * @param array<int, string> $validRelations
     * @param array<int, array{method: string, fqcn: string}> $missingRelations
     */
    private function buildLoadSnippet(string $varName, array $validRelations, array $missingRelations): string
    {
        $indent = '        ';
        $lines = [];

        if (!empty($validRelations)) {
            $relationsStr = "'" . implode("','", $validRelations) . "'";
            $lines[] = "{$varName}->load([{$relationsStr}]);";
        }

        foreach ($missingRelations as $rel) {
            $method = $rel['method'];
            $fqcn = $rel['fqcn'];
            $lines[] = "// {$varName}->load(['{$method}']); // TODO: Generate {$fqcn} to enable this relation.";
        }

        if (empty($lines)) {
            return '';
        }

        return implode("\n{$indent}", $lines) . "\n\n{$indent}";
    }

    private function getFillableWithoutRelations($fillable)
    {
        return collect($fillable)->filter(function ($column) {
            return !Str::endsWith($column, '_id');
        })->toArray();
    }

    private function getTableForeignKeys(string $connection, string $dbName, string $tableName): array
    {


        // Execute a raw query to get the foreign keys for a table
        $foreignKeys = DB::connection($connection)->select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_COLUMN_NAME IS NOT NULL",
            [$dbName, $tableName]
        );

        $result = [];

        foreach ($foreignKeys as $foreignKey) {
            $result[$foreignKey->COLUMN_NAME] = [
                'table' => $foreignKey->REFERENCED_TABLE_NAME,
                'column' => $foreignKey->COLUMN_NAME
            ];
        }

        return $result;
    }

    private function generateControllerContent($modelName, $fillable, $hasRelations, bool $hasSlug = false){

        $humanName = ucwords(str_replace('_',' ',Str::snake($modelName)));
        $humanNamePlural = Str::plural($humanName);

        $relationsInfo = $this->getRelationsInfoByFillable($fillable);
        $validRelations = $relationsInfo['valid'];
        $missingRelations = $relationsInfo['missing'];

        $filtersFields = $this->getFillableWithoutRelations($fillable);

        $filtersFieldsStr = "'".implode("','",$filtersFields)."'";

        // Generate load statements conditionally
        $indexLoadStatement = $this->buildLoadSnippet('$items', $validRelations, $missingRelations);
        $storeLoadStatement = $this->buildLoadSnippet('$item', $validRelations, $missingRelations);
        $showLoadStatement = $this->buildLoadSnippet('$item', $validRelations, $missingRelations);
        $createLoadStatement = $this->buildLoadSnippet('$item', $validRelations, $missingRelations);
        $updateLoadStatement = $this->buildLoadSnippet('$item', $validRelations, $missingRelations);

        $showParam = $hasSlug ? '$slugOrId' : '$id';
        $showFindStatement = $hasSlug
            ? "\$item = is_numeric(\$slugOrId)\n            ? {$modelName}::whereKey(\$slugOrId)->first()\n            : {$modelName}::where('slug', \$slugOrId)->first();"
            : "\$item = {$modelName}::find(\$id);";

        $content = <<<EOT
<?php

namespace App\Http\Controllers;

use App\Http\Resources\\${modelName} as ${modelName}Resource;
use App\Models\\${modelName};
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class ${modelName}Controller extends BaseController
{
    /**
     * Display a listing of the resource.
     *
     * @return JsonResponse
     */
    public function index(Request \$request): JsonResponse
    {
        \$query = ${modelName}::query();

        [\$perPage, \$page, \$fieldsToSelect, \$searchStr, \$from] = \$this->buildParamsFromRequest(\$request, \$query);

        \$query->select(\$fieldsToSelect);

        \$this->addSearchCriteria(\$searchStr, \$query, [${filtersFieldsStr}]);

        \$orderStr = \$request->get('order','id:asc');

        \$filters = \$this->extractFilters(\$request,${modelName}::class);

        \$this->addFiltersCriteria(\$query,\$filters,${modelName}::class);

        [\$totalRows, \$items] = \$this->addCountQueryAndExecute(\$orderStr, \$query, \$from, \$perPage);

        ${indexLoadStatement}\$response = [
            'items' => ${modelName}Resource::collection(\$items),
            'totalItems' => \$totalRows,
            'totalPages' => ceil(\$totalRows/\$perPage),
            'page' => \$page,
            'perPage' => \$perPage,
            'order' => \$orderStr,
            'search' => \$searchStr,
            'filters' => \$filters,
        ];

        return \$this->sendResponse(\$response,trans('${humanNamePlural} retrieved successfully'));
    }



   /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return JsonResponse
     */
    public function store(Request \$request): JsonResponse
    {
        \$input = \$request->all();

        \$validator = Validator::make(\$input, ${modelName}::getRules());

        if(\$validator->fails()){
            return \$this->sendError(trans('Validation Error'), \$validator->errors(),400);
        }

        try{
            \$item = ${modelName}::create(\$input);
        }catch(\Exception \$e){
            return \$this->sendError(\$e->getMessage(),[],409);
        }

        ${storeLoadStatement}return \$this->sendResponse(new ${modelName}Resource(\$item),trans('${humanName} created successfully'));
    }

    /**
     * Display the specified resource.
     *
     * @param \$id
     * @return JsonResponse
     */
    public function show(${showParam}): JsonResponse
    {
        ${showFindStatement}

        if(is_null(\$item)){
            return \$this->sendError(trans('${humanName} not found'));
        }

        ${showLoadStatement}return \$this->sendResponse(new ${modelName}Resource(\$item),trans('${humanName} retrieved successfully'));

    }

     /**
     * Show the form for creating a new resource
     *
     * @param  \Illuminate\Http\Request  \$request
     * @return JsonResponse
     */
    public function create(Request \$request): JsonResponse
    {
        \$input = \$request->all();

        \$item = new ${modelName}(\$input);

        ${createLoadStatement}return \$this->sendResponse(new ${modelName}Resource(\$item),null);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Request \$request
     * @param \$id
     * @return JsonResponse
     */
    public function update(Request \$request, \$id)
    {
        \$item = ${modelName}::find(\$id);

        if(is_null(\$item)){
            return \$this->sendError(trans('${humanName} not found'));
        }

        \$input = \$request->all();

         \$rules = ${modelName}::getRules(\$id);

        foreach (\$rules as \$k => \$v) {
            if (!array_key_exists(\$k, \$input)) {
                unset(\$rules[\$k]);
            }
        }

        \$validator = Validator::make(\$input,\$rules);

        if(\$validator->fails()){
            return \$this->sendError(trans('Validation Error'), \$validator->errors(),400);
        }

        \$item->fill(\$input);

        try{
            \$item->save();
        }catch(\Exception \$e){
            return \$this->sendError(\$e->getMessage(),[],409);
        }

        \$item->fresh();

        ${updateLoadStatement}return \$this->sendResponse(new ${modelName}Resource(\$item), trans('${humanName} updated successfully'));

    }


    /**
     * Remove the specified resource from storage.
     *
     * @param \$id
     * @return JsonResponse
     */
    public function destroy(\$id)
    {
        \$item = ${modelName}::find(\$id);

        if(is_null(\$item)){
            return \$this->sendError(trans('${humanName} not found'));
        }

        try{
            \$item->delete();
        }catch(\Exception \$e){
            return \$this->sendError(\$e->getMessage(),[],409);
        }

        return \$this->sendResponse([], trans('${humanName} deleted successfully'));

    }
}

EOT;


        return $content;


    }

    /**
     * Insert a resource route for the given controller into the API routes file.
     * - tenant-scoped routes go inside the tenancy middleware group
     * - central-scoped routes go in the central/public section (outside the tenant group)
     */
    protected function appendResourceRoute(string $controller, bool $isTenantScoped = false): void
    {
        $routesFile = base_path('routes/api.php');

        $this->addUseStatement($controller);

        $fileContents = file_get_contents($routesFile);
        if ($fileContents === false) {
            throw new \RuntimeException('Unable to read routes/api.php');
        }

        $resourcePath = $this->getResourcePathName($controller);

        // Avoid duplicates
        if (str_contains($fileContents, "resource('{$resourcePath}'") || str_contains($fileContents, "resource(\"{$resourcePath}\"")) {
            return;
        }

        $routeStatement = $isTenantScoped
            ? "Route::resource('{$resourcePath}', {$controller}::class)->except(['edit']);"
            : "Route::middleware(['auth:sanctum'])->resource('{$resourcePath}', {$controller}::class)->except(['edit']);";

        if ($isTenantScoped) {
            $groupEndPos = strrpos($fileContents, "});");
            if ($groupEndPos === false) {
                // Fallback: append at end.
                $fileContents .= PHP_EOL . $routeStatement . PHP_EOL;
            } else {
                $insertion = '    ' . $routeStatement . PHP_EOL;
                $fileContents = substr_replace($fileContents, $insertion, $groupEndPos, 0);
            }
        } else {
            $insertPos = strpos($fileContents, "// Central-domain webhooks");
            if ($insertPos === false) {
                $insertPos = strpos($fileContents, "Route::middleware([");
            }

            $insertion = $routeStatement . PHP_EOL . PHP_EOL;

            if ($insertPos === false) {
                $fileContents .= PHP_EOL . $insertion;
            } else {
                $fileContents = substr_replace($fileContents, $insertion, $insertPos, 0);
            }
        }

        file_put_contents($routesFile, $fileContents);
    }

    protected function getResourcePathName($controller)
    {
        $name = str_replace('-controller','',Str::kebab($controller));
        $name = str_replace('-',' ',$name);
        $name = Str::plural($name);
        $name = str_replace(' ','-',$name);
        return $name;
    }

    /**
     * Add the use statement for the given controller after the last use statement in the API routes file.
     *
     * @param string $controller
     * @return void
     */
    protected function addUseStatement($controller)
    {
        $useStatement = 'use \App\Http\Controllers\\' . $controller . ' as ' . $controller . ';' . PHP_EOL;

        $routesFile = base_path('routes/api.php');
        $fileContents = file_get_contents($routesFile);
        if ($fileContents === false) {
            throw new \RuntimeException('Unable to read routes/api.php');
        }

        // Avoid duplicates (support both aliased and non-aliased imports)
        if (
            str_contains($fileContents, "\\App\\Http\\Controllers\\{$controller}") ||
            str_contains($fileContents, "use App\\Http\\Controllers\\{$controller}")
        ) {
            return;
        }

        $lastUseStatementPosition = strrpos($fileContents, 'use ');

        if ($lastUseStatementPosition !== false) {
            $lastUseStatementPosition = strpos($fileContents, PHP_EOL, $lastUseStatementPosition);
            if ($lastUseStatementPosition !== false) {
                $lastUseStatementPosition += strlen(PHP_EOL);
            }

            $fileContents = substr_replace($fileContents, $useStatement, $lastUseStatementPosition, 0);
        }

        file_put_contents($routesFile, $fileContents);
    }

    /**
     * Generate Postman documentation for the given controller.
     *
     * @param string $controller
     * @return void
     */
    protected function generatePostmanDocumentation($controller,$fillable)
    {
        $routePath = $this->getResourcePathName($controller);

        $collectionId =  env('POSTMAN_COLLECTION_ID', 'YOUR_COLLECTION_ID');
        $postmanApiKey = env('POSTMAN_API_KEY','YOUR_POSTMAN_API_KEY');

        if($collectionId === 'YOUR_COLLECTION_ID' || $postmanApiKey === 'YOUR_POSTMAN_API_KEY'){
            //$this->error("Please set POSTMAN_COLLECTION_ID and POSTMAN_API_KEY in your .env file to generate Postman documentation.");
            return;
        }

        $client = new Client([
            'base_uri' => 'https://api.getpostman.com/',
            'headers' => [
                'X-Api-Key' => $postmanApiKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/vnd.postman.v2+json',
            ],
        ]);

        $humanReadableName = ucwords(preg_replace('/(?!^)([A-Z])/', ' $0',str_replace('Controller','',$controller)));


        $data = [];
        foreach($fillable as $field){
            $data[] = [
                'key' => $field,
                'value' => '',
                'type' => 'text',
                'enabled' => true
            ];
        }

        $requests =  [
            [
                'id' => $this->uuidV4(),
                'name' => 'Get All ' . Str::plural($humanReadableName),
                'method' => 'GET',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath.'?page=1&perPage=100&search=',
                'responses' => [],
                'data' => [],
                'dataMode' => 'params'

            ],
            [
                'id' => $this->uuidV4(),
                'name' => 'Create ' . $humanReadableName,
                'method' => 'POST',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath,
                'responses' => [],
                'data' => $data,
                'dataMode' => 'params',

            ],
            [
                'id' => $this->uuidV4(),
                'name' => 'Get  ' . $humanReadableName,
                'method' => 'GET',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath . '/{id}',
                'responses' => [],
                'data' => [],
                'dataMode' => 'params'

            ],
            [
                'id' => $this->uuidV4(),
                'name' => 'Update ' . $humanReadableName,
                'method' => 'PUT',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath . '/{id}?'.implode('=&',$fillable),
                'responses' => [],
                'data' => [],
                'dataMode' => 'params',
            ],
            [
                'id' => $this->uuidV4(),
                'name' => $humanReadableName.' create Form',
                'method' => 'GET',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath . '/create?'.implode('=&',$fillable),
                'responses' => [],
                'data' => [],
                'dataMode' => 'params',
            ],
            [
                'id' => $this->uuidV4(),
                'name' => 'Delete ' . $humanReadableName,
                'method' => 'DELETE',
                'headers' => '',
                'url' => '{{host}}/api/' . $routePath . '/{id}',
                'responses' => [],
                'data' => [],
                'dataMode' => 'params',
            ],
        ];

        $body = [
            'json' => [
                'name' => $humanReadableName,
                'description' => '',
                'requests' => $requests
            ],
        ];

        $client->post("collections/{$collectionId}/folders",$body);

        $this->info("Postman documentation generated for '{$controller}' and added as a new group to the existing collection.");
    }

    public function uuidV4()
    {
        return sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',

            // 32 bits for "time_low"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),

            // 16 bits for "time_mid"
            mt_rand(0, 0xffff),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            mt_rand(0, 0x0fff) | 0x4000,

            // 16 bits, 8 bits for "clk_seq_hi_res",
            // 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            mt_rand(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
