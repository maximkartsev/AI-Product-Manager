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
    protected $signature = 'create:model-controller {table : Table name} {entity : Entity name} {--only= : Specify what to create: model,controller,resource,doc,route}';

    protected $description = 'Create model and controller based on given table structure';

    public function handle()
    {
        $table = $this->argument('table');
        $entity = $this->argument('entity');
        $only = $this->option('only');

        // Parse the --only option
        $createComponents = $this->parseOnlyOption($only);

        $model = ucwords(Str::camel(Str::singular($entity)));
        $controllerName = $model . 'Controller';

        $fillable = $this->getColumns($table)->reject(function ($column) {
            return in_array($column, ['id', 'created_at', 'updated_at']);
        })->toArray();

        $relations = $this->getRelationsByFillable($fillable);
        $hasRelations = !empty($relations);

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
            // Generate model file
            Artisan::call('make:model', [
                'name' => $model,
            ]);

            $modelContent = file_get_contents($modelPath);

            $fillableContent = "\n\n protected ".'$fillable=['."\n'".implode("',\n'",$fillable)."\n\n". "\n".'];'."\n\n";

            $castsContent = $this->generateCasts($table);

            $rulesContent = $this->generateGetRulesFunction($table);

            $belongsToRelations = collect($fillable)->filter(function ($column) {
                return Str::endsWith($column, '_id');
            })->map(function ($column) {
                return ucfirst(Str::camel(str_replace('_id', '', Str::snake($column))));
            })->map(function ($relatedModel) {
                $methodName = lcfirst($relatedModel);
                return "public function {$methodName}() {\n    return \$this->belongsTo(\\App\\Models\\{$relatedModel}::class);\n}";
            })->implode("\n\n");

            $modelContent = str_replace('extends Model', 'extends BaseModel', $modelContent);

            $modelContent = str_replace("extends BaseModel\n{", "extends BaseModel\n{\n\n" . $fillableContent . $castsContent . $rulesContent . $belongsToRelations, $modelContent);

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
            $controllerContent = $this->generateControllerContent($model, $fillable, $hasRelations);
            file_put_contents($controllerPath, $controllerContent);
            $this->info("Controller {$controller} created successfully.");
        }

        // Generate translations if any component was created
//        if ($createComponents['translations']) {
//            Artisan::call('translations:scan');
//        }

        // Add route if specified
        if ($createComponents['route']) {
            $this->appendResourceRoute($controller);
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

    protected function checkIfColumnIsNullable($dbName,$tableName,$columnName){
        $results = DB::select(
            "SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE table_schema = ? AND table_name = ? AND COLUMN_NAME = ?",
            [$dbName, $tableName, $columnName]
        );


        if (!empty($results)) {
            $columnInfo = $results[0]; // Get the first (and in this case, only) result

            // Check if the column is nullable
            $isNullable = $columnInfo->IS_NULLABLE === 'YES';

            // Print whether the column is nullable
            return $isNullable;
        } else {
            throw new Exception('Column information not found');
        }
    }

    protected function generateGetRulesFunction($tableName)
    {
        $rules = collect();

        // get Doctrine Schema Manager


        $columns = Schema::getColumnListing($tableName);
        $primaryKey = $columns[0];

        $connection = DB::connection();



        foreach ($columns as $column) {
            if (in_array($column,['created_at','updated_at',$primaryKey])) {
                continue;
            }

            $columnName = $column;
            $columnType =  Schema::getColumnType($tableName, $columnName);



            $nullable =  $this->checkIfColumnIsNullable(getenv('DB_DATABASE'),$tableName,$columnName);

            $columnRules = collect();

            if ($columnType === 'integer' || $columnType === 'float' || $columnType === 'double' || $columnType == 'bigint' || $columnType == 'decimal') {
                $columnRules->push('numeric');
            } elseif ($columnType === 'boolean') {
                $columnRules->push('boolean');
            } elseif ($columnType === 'date') {
                $columnRules->push('date');
            } elseif ($columnType === 'datetime' || $columnType === 'timestamp') {
                $columnRules->push('date_format:Y-m-d H:i:s');
            } else {
                $columnRules->push('string');
            }

            if ($nullable) {
                $columnRules->push('nullable');
            } else {
                $columnRules->push('required');
            }

            // If column is a foreign key, add an 'exists' validation rule
            $foreignKeys = $this->getTableForeignKeys($tableName);
            foreach ($foreignKeys as $localColumn => $foreignKey) {
                if ($localColumn === $columnName) {
                    $relatedTable = $foreignKey['table'];
                    $relatedColumn = $foreignKey['column'];
                    $columnRules->push("exists:$relatedTable,id");
                }
            }

            $rules->put($columnName, $columnRules->implode('|'));
        }

        $function = "public static function getRules(\$id=null)\n{\n    return [\n";

        foreach ($rules as $column => $rule) {
            $function .= "        '{$column}' => '{$rule}',\n";
        }

        $function .= "    ];\n}";

        return $function;
    }

    protected function generateCasts($tableName)
    {
        $rules = collect();

        $columns = Schema::getColumnListing($tableName);
        $primaryKey = $columns[0];

        $casts = [];

        foreach ($columns as $column) {
            if (in_array($column,['created_at','updated_at',$primaryKey])) {
                continue;
            }

            if(str_ends_with($column, '_id')){
                continue;
            }

            $columnName = $column;

            // get column type
            $columnType = Schema::getColumnType($tableName, $columnName);

            switch($columnType){
                case 'integer':
                    $casts[$columnName] = 'int';
                    break;
                case 'float':
                case 'double':
                case 'decimal':
                    $casts[$columnName] = 'float';
                    break;
                default:
                    break;
            }
        }

        $content = "protected \$casts = [\n";

        foreach ($casts as $column => $type) {
            $content .= "        '{$column}' => '{$type}',\n";
        }

        $content .= "    ];\n";

        return $content."\n";
    }

    private function getColumns($table)
    {
        $fields = new Collection();

        $columns = DB::select("SHOW COLUMNS FROM $table");

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

    private function getFillableWithoutRelations($fillable)
    {
        return collect($fillable)->filter(function ($column) {
            return !Str::endsWith($column, '_id');
        })->toArray();
    }

    private function getTableForeignKeys($tableName)
    {


        // Execute a raw query to get the foreign keys for a table
        $foreignKeys = DB::select(
            "SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
    FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
    WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_COLUMN_NAME IS NOT NULL",
            [env('DB_DATABASE'), $tableName]
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

    private function generateControllerContent($modelName,$fillable,$hasRelations){

        $humanName = ucwords(str_replace('_',' ',Str::snake($modelName)));
        $humanNamePlural = Str::plural($humanName);

        $relations = $this->getRelationsByFillable($fillable);

        $relationsStr = "'".implode("','",$relations)."'";

        $filtersFields = $this->getFillableWithoutRelations($fillable);

        $filtersFieldsStr = "'".implode("','",$filtersFields)."'";

        // Generate load statements conditionally
        $indexLoadStatement = $hasRelations ? "\$items->load([{$relationsStr}]);\n\n        " : '';
        $storeLoadStatement = $hasRelations ? "\$item->load([{$relationsStr}]);\n\n        " : '';
        $showLoadStatement = $hasRelations ? "\$item->load([{$relationsStr}]);\n\n        " : '';
        $createLoadStatement = $hasRelations ? "\$item->load([{$relationsStr}]);\n\n        " : '';
        $updateLoadStatement = $hasRelations ? "\$item->load([{$relationsStr}]);\n\n        " : '';

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
    public function show(\$id): JsonResponse
    {
        \$item = ${modelName}::find(\$id);

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
     * Append a resource route with auth:sanctum middleware for the given controller to the API routes file.
     *
     * @param string $controller
     * @return void
     */
    protected function appendResourceRoute($controller)
    {
        $this->addUseStatement($controller);

        $middlewares = "['auth:sanctum']"; // will add 'role-access' later


        $routeContent = PHP_EOL . "Route::middleware(".$middlewares.")->resource('" . $this->getResourcePathName($controller) . "', {$controller}::class)->except(['edit']);";

        $routesFile = base_path('routes/api.php');
        file_put_contents($routesFile, $routeContent, FILE_APPEND);
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
