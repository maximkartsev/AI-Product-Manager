<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\OpenAI as OpenAIModel;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

class GenerateOpenApi extends Command
{
    /**
     * @var array<string, string|null>
     */
    private array $methodSourceCache = [];

    /**
     * @var array<string, array{properties: array<string, mixed>, required: array<int, string>}|null>
     */
    private array $modelSchemaCache = [];

    /**
     * @var array<string, array<string, array<string, mixed>|null>>
     */
    private array $dbColumnMetaCache = [];

    /**
     * @var \Faker\Generator|null
     */
    private $faker = null;

    /**
     * OpenAPI component examples collected during generation.
     *
     * @var array<string, array<string, mixed>>
     */
    private array $componentsExamples = [];

    /**
     * Cache for OpenAI-generated examples per model class.
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $openAiExampleCache = [];

    protected $signature = 'openapi:generate {--output= : Output file path (defaults to ../openapi/openapi.yaml)}';

    protected $description = 'Generate OpenAPI 3.0.3 spec from Laravel API routes';

    public function handle(): int
    {
        $this->initFaker();

        $outputPath = $this->option('output');

        if (!is_string($outputPath) || $outputPath === '') {
            $repoRoot = dirname(base_path());
            $outputPath = $repoRoot . '/openapi/openapi.yaml';
        }

        $spec = $this->buildSpec();

        $yaml = Yaml::dump(
            $spec,
            20,
            2,
            Yaml::DUMP_OBJECT_AS_MAP | Yaml::DUMP_EMPTY_ARRAY_AS_SEQUENCE
        );

        File::ensureDirectoryExists(dirname($outputPath));
        File::put($outputPath, $yaml);

        $this->info('OpenAPI written to: ' . $outputPath);

        return self::SUCCESS;
    }

    private function initFaker(): void
    {
        if ($this->faker !== null) {
            return;
        }

        if (class_exists(\Faker\Factory::class)) {
            $this->faker = \Faker\Factory::create();
            $this->faker->seed(1337);
        }
    }

    private function buildSpec(): array
    {
        $this->componentsExamples = [];
        $this->openAiExampleCache = [];

        $paths = [];
        $tagsSet = [];

        /** @var \Illuminate\Routing\RouteCollectionInterface $routes */
        $routes = app('router')->getRoutes();

        foreach ($routes as $route) {
            $this->info("Processing route: " . $route->uri());
            if (!$route instanceof Route) {
                continue;
            }

            $uri = ltrim($route->uri(), '/');
            if (!str_starts_with($uri, 'api/')) {
                continue;
            }

            if ($this->isExcludedApiUri($uri)) {
                continue;
            }

            $path = '/' . $uri;
            $action = $route->getActionName();
            $name = $route->getName();
            $middleware = $route->gatherMiddleware();
            $requiresAuth = $this->requiresSanctum($middleware);

            $tag = $this->tagFromPath($path);
            $tagsSet[$tag] = true;

            $methods = array_values(array_diff($route->methods(), ['HEAD', 'OPTIONS']));

            foreach ($methods as $httpMethod) {
                $httpMethodUpper = strtoupper($httpMethod);
                $httpMethodLower = strtolower($httpMethod);

                $operation = [
                    'tags' => [$tag],
                    'operationId' => $this->buildOperationId($action, $name, $httpMethodUpper, $path),
                    'summary' => $this->buildSummary($action, $name, $httpMethodUpper, $path),
                    'parameters' => $this->buildParameters($route, $httpMethodUpper, $action),
                    'responses' => $this->buildResponses($route, $httpMethodUpper, $path, $action, $name),
                ];

                if ($requiresAuth) {
                    $operation['security'] = [['bearerAuth' => []]];
                }

                $requestBody = $this->buildRequestBody($route, $httpMethodUpper, $action);
                if ($requestBody !== null) {
                    $operation['requestBody'] = $requestBody;
                }

                $operation['description'] = $this->buildDescription(
                    route: $route,
                    httpMethod: $httpMethodUpper,
                    path: $path,
                    tag: $tag,
                    action: $action,
                    routeName: $name,
                    middleware: $middleware,
                    requiresAuth: $requiresAuth,
                    requestBody: $requestBody,
                    parameters: $operation['parameters'] ?? []
                );

                if (empty($operation['parameters'])) {
                    unset($operation['parameters']);
                }

                $paths[$path][$httpMethodLower] = $operation;
            }
        }

        ksort($paths);

        $tags = [];
        foreach (array_keys($tagsSet) as $tagName) {
            $tags[] = ['name' => $tagName];
        }

        $appName = config('app.name') ?: 'API';

        return [
            'openapi' => '3.0.3',
            'info' => [
                'title' => $appName . ' API',
                'version' => '1.0.0',
                'description' => 'Auto-generated from Laravel routes.',
            ],
            'servers' => [
                ['url' => config('app.url') ?: 'http://localhost'],
            ],
            'tags' => $tags,
            'paths' => $paths,
            'components' => [
                'securitySchemes' => [
                    'bearerAuth' => [
                        'type' => 'http',
                        'scheme' => 'bearer',
                        'bearerFormat' => 'Bearer',
                    ],
                ],
                'schemas' => [
                    'ApiSuccess' => [
                        'type' => 'object',
                        'required' => ['success', 'data', 'message'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => true],
                            'data' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                            'message' => ['type' => 'string', 'nullable' => true],
                        ],
                    ],
                    'ApiError' => [
                        'type' => 'object',
                        'required' => ['success', 'message'],
                        'properties' => [
                            'success' => ['type' => 'boolean', 'example' => false],
                            'message' => ['type' => 'string'],
                            'data' => [
                                'type' => 'object',
                                'additionalProperties' => true,
                            ],
                        ],
                    ],
                    'Unauthenticated' => [
                        'type' => 'object',
                        'required' => ['message'],
                        'properties' => [
                            'message' => ['type' => 'string', 'example' => 'Unauthenticated'],
                        ],
                    ],
                ],
                'examples' => $this->componentsExamples,
            ],
        ];
    }

    private function isExcludedApiUri(string $uri): bool
    {
        return $uri === 'api/test-invoice' || str_starts_with($uri, 'api/test/');
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function requiresSanctum(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (is_string($m) && str_contains($m, 'sanctum')) {
                return true;
            }
        }

        return false;
    }

    private function tagFromPath(string $path): string
    {
        $trimmed = ltrim($path, '/'); // api/...
        $parts = explode('/', $trimmed);

        return $parts[1] ?? 'api';
    }

    private function buildOperationId(string $action, ?string $name, string $httpMethod, string $path): string
    {
        if ($action !== 'Closure' && str_contains($action, '@')) {
            [$class, $method] = explode('@', $action, 2);
            $classBase = class_basename($class);
            $op = $classBase . '_' . $method;
        } elseif (is_string($name) && $name !== '') {
            $op = str_replace(['.', '-'], '_', $name);
        } else {
            $op = strtolower($httpMethod) . '_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $path);
        }

        $op .= '_' . strtolower($httpMethod);

        if (!preg_match('/^[A-Za-z_]/', $op)) {
            $op = '_' . $op;
        }

        return (string)preg_replace('/_+/', '_', $op);
    }

    private function buildSummary(string $action, ?string $name, string $httpMethod, string $path): string
    {
        if (is_string($name) && $name !== '') {
            return $name;
        }

        if ($action !== 'Closure') {
            return $action;
        }

        return $httpMethod . ' ' . $path;
    }

    /**
     * @param array<int, mixed> $middleware
     * @param array<int, array<string, mixed>> $parameters
     * @param array<string, mixed>|null $requestBody
     */
    private function buildDescription(
        Route $route,
        string $httpMethod,
        string $path,
        string $tag,
        string $action,
        ?string $routeName,
        array $middleware,
        bool $requiresAuth,
        ?array $requestBody,
        array $parameters
    ): string {
        $lines = [];

        $purpose = $this->buildPurposeLine(
            route: $route,
            httpMethod: $httpMethod,
            path: $path,
            tag: $tag,
            action: $action,
            routeName: $routeName
        );
        $lines[] = $purpose;

        $docText = $this->getActionDocText($action);
        if (is_string($docText) && $docText !== '' && !$this->isGenericDocText($docText)) {
            $lines[] = '';
            $lines[] = '**Notes**';
            $lines[] = $docText;
        }

        $lines[] = '';
        $lines[] = '**Authentication**';
        $authLine = $requiresAuth
            ? 'Requires a valid Sanctum bearer token (`Authorization: Bearer <token>`).'
            : 'Public endpoint (no Sanctum authentication).';

        $requiresRoleAccess = $this->requiresRoleAccess($middleware);
        if ($requiresAuth && $requiresRoleAccess) {
            $authLine .= ' Also subject to role-based access control.';
        }
        $lines[] = $authLine;

        $pathParams = $this->describeParams($parameters, 'path');
        if (!empty($pathParams)) {
            $lines[] = '';
            $lines[] = '**Path parameters**';
            foreach ($pathParams as $p) {
                $lines[] = '- ' . $p;
            }
        }

        $queryParams = $this->describeParams($parameters, 'query');
        if (!empty($queryParams)) {
            $lines[] = '';
            $lines[] = '**Query parameters**';
            foreach ($queryParams as $p) {
                $lines[] = '- ' . $p;
            }
        }

        $bodyLines = $this->describeRequestBody($requestBody);
        if (!empty($bodyLines)) {
            $lines[] = '';
            $lines[] = '**Request body**';
            foreach ($bodyLines as $l) {
                $lines[] = $l;
            }
        }

        $lines[] = '';
        $lines[] = '**Response**';
        if ($this->isBinaryResponsePath($path)) {
            $lines[] = 'Returns a binary file payload (download/preview).';
        } else {
            $lines[] = 'Returns JSON using the standard envelope: `{success, data, message}`.';
        }

        return implode("\n", $lines);
    }

    private function buildPurposeLine(
        Route $route,
        string $httpMethod,
        string $path,
        string $tag,
        string $action,
        ?string $routeName
    ): string {
        [$class, $method] = $this->parseControllerAction($action);

        $resourceKey = $this->resourceKeyFromRouteNameOrTag($routeName, $tag);
        $resourceLabel = $this->humanizeKey(Str::plural($resourceKey));
        $resourceSingularLabel = $this->humanizeKey(Str::singular($resourceKey));

        $methodName = $method ?? '';
        $methodLower = strtolower($methodName);
        $pathLower = strtolower($path);

        if ($path === '/api/me' && $httpMethod === 'GET') {
            return 'Get the current authenticated user profile (\"me\").';
        }

        if ($path === '/api/register' && $httpMethod === 'POST') {
            return 'Register a new user account and issue an access token.';
        }

        if ($path === '/api/login' && $httpMethod === 'POST') {
            return 'Authenticate user credentials and issue an access token (MFA may be required).';
        }

        if ($methodName === 'index' && $httpMethod === 'GET') {
            $paramNames = $route->parameterNames();
            if (!empty($paramNames)) {
                $paramLabel = $this->humanizeKey((string)($paramNames[0] ?? 'parameter'));
                return "Get {$resourceLabel} for the specified {$paramLabel}.";
            }
            return "List {$resourceLabel}. Supports pagination, ordering, and search where available.";
        }
        if ($methodName === 'store' && $httpMethod === 'POST') {
            return "Create a new {$resourceSingularLabel}.";
        }
        if ($methodName === 'show' && $httpMethod === 'GET') {
            return "Get a single {$resourceSingularLabel} by identifier.";
        }
        if ($methodName === 'update' && in_array($httpMethod, ['PUT', 'PATCH'], true)) {
            return "Update an existing {$resourceSingularLabel} by identifier.";
        }
        if ($methodName === 'destroy' && $httpMethod === 'DELETE') {
            return "Delete an existing {$resourceSingularLabel} by identifier.";
        }
        if ($methodName === 'create' && $httpMethod === 'GET') {
            return "Get defaults/metadata used to create a {$resourceSingularLabel}.";
        }

        $byFilter = $this->extractByFilterFromPath($path);
        if ($httpMethod === 'GET' && is_string($byFilter) && $byFilter !== '') {
            $filterLabel = $this->humanizeKey(Str::singular($byFilter));
            return "List {$resourceLabel} for a given {$filterLabel}.";
        }

        if (str_contains($pathLower, '/slug/') || str_contains($methodLower, 'slug')) {
            return "Get a {$resourceSingularLabel} by slug.";
        }

        if (str_contains($pathLower, '/webhook') || str_contains($methodLower, 'webhook')) {
            $provider = $this->extractWebhookProviderFromPath($path);
            return $provider ? "Receive {$provider} webhook callbacks." : 'Receive webhook callbacks.';
        }

        if (str_contains($pathLower, '/download') || str_contains($methodLower, 'download')) {
            return "Download {$resourceSingularLabel} file content.";
        }

        if (str_contains($pathLower, '/preview') || str_contains($methodLower, 'preview')) {
            return "Preview {$resourceSingularLabel} file/content.";
        }

        if (str_contains($methodLower, 'statistics')) {
            return "Get {$resourceLabel} statistics.";
        }

        if (Str::startsWith($methodName, 'redirectTo')) {
            $target = substr($methodName, strlen('redirectTo'));
            $targetLabel = $target !== '' ? $this->humanizeCamelCase($target) : 'external provider';
            return "Get an OAuth redirect URL for {$targetLabel}.";
        }

        if (Str::endsWith($methodName, 'Callback') && Str::startsWith($methodName, 'handle')) {
            return 'Handle an OAuth callback and return an access token for the authenticated user.';
        }

        if ($methodName !== '') {
            return ucfirst($this->humanizeCamelCase($methodName)) . '.';
        }

        // Fallback based purely on HTTP method
        return match ($httpMethod) {
            'GET' => 'Retrieve data.',
            'POST' => 'Create or trigger an action.',
            'PUT', 'PATCH' => 'Update data.',
            'DELETE' => 'Delete data.',
            default => 'Perform an action.',
        };
    }

    private function resourceKeyFromRouteNameOrTag(?string $routeName, string $tag): string
    {
        if (is_string($routeName) && $routeName !== '' && str_contains($routeName, '.')) {
            [$first] = explode('.', $routeName, 2);
            if (is_string($first) && $first !== '') {
                return $first;
            }
        }

        return $tag;
    }

    private function humanizeKey(string $key): string
    {
        $key = str_replace(['-', '_'], ' ', $key);
        return ucwords(trim($key));
    }

    private function humanizeCamelCase(string $value): string
    {
        $value = preg_replace('/(?<!^)[A-Z]/', ' $0', $value) ?: $value;
        $value = str_replace(['_', '-'], ' ', $value);
        return strtolower(trim($value));
    }

    private function extractByFilterFromPath(string $path): ?string
    {
        $segments = array_values(array_filter(explode('/', trim($path, '/'))));
        // expected: api/{resource}/by-foo/{id}
        foreach ($segments as $seg) {
            if (str_starts_with($seg, 'by-') && strlen($seg) > 3) {
                return substr($seg, 3);
            }
        }

        return null;
    }

    private function extractWebhookProviderFromPath(string $path): ?string
    {
        $lower = strtolower($path);
        if (str_contains($lower, 'monobank')) {
            return 'Monobank';
        }
        if (str_contains($lower, 'video-call')) {
            return 'video call recording';
        }

        return null;
    }

    /**
     * @param array<int, mixed> $middleware
     */
    private function requiresRoleAccess(array $middleware): bool
    {
        foreach ($middleware as $m) {
            if (!is_string($m)) {
                continue;
            }
            if (str_contains($m, 'RoleBasedAccess') || str_contains($m, 'role-access')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, array<string, mixed>> $parameters
     * @return array<int, string>
     */
    private function describeParams(array $parameters, string $in): array
    {
        $out = [];

        foreach ($parameters as $p) {
            if (!is_array($p) || ($p['in'] ?? null) !== $in) {
                continue;
            }

            $name = $p['name'] ?? null;
            if (!is_string($name) || $name === '') {
                continue;
            }

            $required = (bool)($p['required'] ?? false);
            $schema = $p['schema'] ?? null;
            $type = is_array($schema) ? ($schema['type'] ?? null) : null;
            $format = is_array($schema) ? ($schema['format'] ?? null) : null;

            $typeLabel = is_string($type) ? $type : 'string';
            if (is_string($format) && $format !== '') {
                $typeLabel .= " ({$format})";
            }

            $suffix = $required ? 'Required.' : 'Optional.';

            $out[] = "`{$name}` ({$typeLabel}). {$suffix}";
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $requestBody
     * @return array<int, string>
     */
    private function describeRequestBody(?array $requestBody): array
    {
        if ($requestBody === null) {
            return [];
        }

        $content = $requestBody['content'] ?? null;
        if (!is_array($content)) {
            return [];
        }

        $contentType = null;
        $schema = null;
        foreach (['application/json', 'multipart/form-data'] as $ct) {
            if (isset($content[$ct]['schema'])) {
                $contentType = $ct;
                $schema = $content[$ct]['schema'];
                break;
            }
        }

        if (!is_string($contentType) || !is_array($schema)) {
            return ['Request body is required, but schema is not available.'];
        }

        $properties = $schema['properties'] ?? [];
        if (!is_array($properties)) {
            $properties = [];
        }

        $required = $schema['required'] ?? [];
        if (!is_array($required)) {
            $required = [];
        }

        $lines = [];
        $lines[] = "- Content-Type: `{$contentType}`";

        if (empty($properties)) {
            $lines[] = '- Fields: not explicitly documented (free-form object).';
            return $lines;
        }

        $reqSet = [];
        foreach ($required as $r) {
            if (is_string($r) && $r !== '') {
                $reqSet[$r] = true;
            }
        }

        $lines[] = '- Fields:';
        foreach ($properties as $name => $propSchema) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $type = is_array($propSchema) ? ($propSchema['type'] ?? null) : null;
            $format = is_array($propSchema) ? ($propSchema['format'] ?? null) : null;
            $typeLabel = is_string($type) ? $type : 'string';
            if (is_string($format) && $format !== '') {
                $typeLabel .= " ({$format})";
            }

            $constraints = $this->schemaConstraintSummary($propSchema);
            $reqLabel = isset($reqSet[$name]) ? 'required' : 'optional';
            $lines[] = "  - `{$name}`: {$typeLabel}{$constraints}, {$reqLabel}";
        }

        return $lines;
    }

    /**
     * @param mixed $schema
     */
    private function schemaConstraintSummary($schema): string
    {
        if (!is_array($schema)) {
            return '';
        }

        $bits = [];

        if (($schema['nullable'] ?? null) === true) {
            $bits[] = 'nullable';
        }

        if (isset($schema['minLength']) || isset($schema['maxLength'])) {
            $min = $schema['minLength'] ?? null;
            $max = $schema['maxLength'] ?? null;
            if (is_int($min) && is_int($max) && $min === $max) {
                $bits[] = "length={$min}";
            } else {
                if (is_int($min)) {
                    $bits[] = "minLength={$min}";
                }
                if (is_int($max)) {
                    $bits[] = "maxLength={$max}";
                }
            }
        }

        if (isset($schema['minimum'])) {
            $min = $schema['minimum'];
            if (is_int($min) || is_float($min)) {
                $bits[] = "min={$min}";
            }
        }
        if (isset($schema['maximum'])) {
            $max = $schema['maximum'];
            if (is_int($max) || is_float($max)) {
                $bits[] = "max={$max}";
            }
        }

        if (isset($schema['minItems'])) {
            $minItems = $schema['minItems'];
            if (is_int($minItems)) {
                $bits[] = "minItems={$minItems}";
            }
        }
        if (isset($schema['maxItems'])) {
            $maxItems = $schema['maxItems'];
            if (is_int($maxItems)) {
                $bits[] = "maxItems={$maxItems}";
            }
        }

        if (isset($schema['enum']) && is_array($schema['enum'])) {
            $enum = array_values($schema['enum']);
            $enumPreview = array_slice($enum, 0, 5);
            $bits[] = 'enum=[' . implode(', ', array_map(fn ($v) => is_scalar($v) ? (string)$v : '…', $enumPreview)) . (count($enum) > 5 ? ', …' : '') . ']';
        }

        if (isset($schema['pattern']) && is_string($schema['pattern']) && $schema['pattern'] !== '') {
            $bits[] = 'pattern';
        }

        if (isset($schema['x-file-max-kb']) && is_int($schema['x-file-max-kb'])) {
            $bits[] = 'maxFileKB=' . $schema['x-file-max-kb'];
        }

        if (empty($bits)) {
            return '';
        }

        return ' (' . implode(', ', $bits) . ')';
    }

    private function isBinaryResponsePath(string $path): bool
    {
        $lower = strtolower($path);
        return str_contains($lower, '/download') || str_contains($lower, '/preview');
    }

    private function getActionDocText(string $action): ?string
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return null;
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException $e) {
            return null;
        }

        $doc = $ref->getDocComment();
        if (!is_string($doc) || $doc === '') {
            return null;
        }

        $textLines = [];
        $lines = preg_split('/\R/', $doc) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);
            // Keep replacements even if they produce an empty string (don't fallback to the original line).
            $line = (string)preg_replace('/^\\/\\*\\*\\s*/', '', $line);
            $line = (string)preg_replace('/^\\*\\s?/', '', $line);
            $line = (string)preg_replace('/\\*\\/\\s*$/', '', $line);
            $line = trim($line);

            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, '@')) {
                break;
            }

            $textLines[] = $line;
        }

        $text = trim(implode(' ', $textLines));
        $text = (string)preg_replace('/\\s+/', ' ', $text);

        return $text !== '' ? $text : null;
    }

    private function isGenericDocText(string $docText): bool
    {
        $normalized = strtolower(trim($docText));
        $normalized = rtrim($normalized, '.');

        $generic = [
            'display a listing of the resource',
            'store a newly created resource in storage',
            'display the specified resource',
            'update the specified resource in storage',
            'remove the specified resource from storage',
            'show the form for creating a new resource',
            'create a new resource',
            'update a resource',
            'delete a resource',
        ];

        foreach ($generic as $g) {
            if ($normalized === $g) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildParameters(Route $route, string $httpMethod, string $action): array
    {
        $params = [];
        $existingParamNames = [];

        foreach ($route->parameterNames() as $paramName) {
            $params[] = [
                'name' => $paramName,
                'in' => 'path',
                'required' => true,
                'schema' => $this->guessSchemaForParam($paramName),
            ];
            $existingParamNames[$paramName] = true;
        }

        $action = $route->getActionName();
        $routeName = $route->getName();
        $isIndex = str_contains($action, '@index') || (is_string($routeName) && str_ends_with($routeName, '.index'));

        // Only apply common list query params for true "index list" routes (no path params).
        $isIndexList = $isIndex && empty($route->parameterNames());

        if ($httpMethod === 'GET' && $isIndexList) {
            $params[] = [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
            $existingParamNames['page'] = true;
            $params[] = [
                'name' => 'perPage',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1],
            ];
            $existingParamNames['perPage'] = true;
            $params[] = [
                'name' => 'search',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ];
            $existingParamNames['search'] = true;
            $params[] = [
                'name' => 'order',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'string'],
            ];
            $existingParamNames['order'] = true;
        }

        if ($httpMethod === 'GET') {
            $queryParamNames = $this->extractQueryParamNamesFromAction($action);
            foreach ($queryParamNames as $queryParamName) {
                if (isset($existingParamNames[$queryParamName])) {
                    continue;
                }

                $params[] = [
                    'name' => $queryParamName,
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string'],
                ];
                $existingParamNames[$queryParamName] = true;
            }
        }

        return $params;
    }

    /**
     * @return array<string, mixed>
     */
    private function guessSchemaForParam(string $name): array
    {
        $lower = strtolower($name);

        if (in_array($lower, ['slug', 'lang', 'locale', 'code'], true)) {
            return ['type' => 'string'];
        }

        if ($lower === 'id' || str_ends_with($lower, 'id') || str_ends_with($lower, '_id')) {
            return ['type' => 'integer', 'format' => 'int64'];
        }

        if (str_contains($lower, 'uuid') || str_contains($lower, 'token')) {
            return ['type' => 'string'];
        }

        return ['type' => 'integer', 'format' => 'int64'];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildRequestBody(Route $route, string $httpMethod, string $action): ?array
    {
        if (!in_array($httpMethod, ['POST', 'PUT', 'PATCH'], true)) {
            return null;
        }

        $uri = '/' . ltrim($route->uri(), '/');
        $validationRules = $this->extractValidationRulesFromAction($action);
        $schema = $this->buildSchemaFromValidationRules($validationRules, false);

        // If controller rules were not detected, try to infer from the corresponding Model.
        if ($this->schemaHasNoExplicitProperties($schema)) {
            [, $methodName] = $this->parseControllerAction($action);
            $modelSchema = $this->buildSchemaFromModelContext($route, $httpMethod, $action);
            if (is_string($methodName) && in_array($methodName, ['store', 'update'], true) && is_array($modelSchema)) {
                $schema = $modelSchema;
            }
        }

        // Decide content-type after schema inference.
        $isMultipart = $this->looksLikeMultipart($uri, $action) || $this->schemaContainsBinary($schema['properties'] ?? null);

        // If multipart but we still have no explicit properties, infer file fields from controller usage (e.g. $request->file('file')).
        if ($isMultipart && $this->schemaHasNoExplicitProperties($schema)) {
            $fileFields = $this->extractFileFieldNamesFromAction($action);
            if (!empty($fileFields)) {
                $properties = [];
                $required = [];

                foreach ($fileFields as $field) {
                    if ($field === 'file') {
                        // Some endpoints accept both a single file and multiple files for `file`.
                        $properties[$field] = [
                            'oneOf' => [
                                ['type' => 'string', 'format' => 'binary'],
                                ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']],
                            ],
                        ];
                    } else {
                        $properties[$field] = ['type' => 'string', 'format' => 'binary'];
                    }
                    $required[] = $field;
                }

                $schema = [
                    'properties' => $properties,
                    'required' => $required,
                ];
            }
        }

        // If webhook and still no explicit fields, infer top-level keys accessed in the controller (best-effort).
        if (!$isMultipart && $this->schemaHasNoExplicitProperties($schema) && str_contains(strtolower($uri), '/webhook')) {
            $keys = $this->extractArrayKeysFromAction($action, '$data');
            if (!empty($keys)) {
                $properties = [];
                foreach ($keys as $k) {
                    $properties[$k] = $this->guessSchemaForWebhookKey($k);
                }

                $schema = [
                    'properties' => $properties,
                    'required' => [],
                ];
            }
        }

        // If we still have no explicit properties and this is not an upload/webhook, the endpoint likely doesn't require a body.
        if (!$isMultipart && $this->schemaHasNoExplicitProperties($schema) && !str_contains(strtolower($uri), '/webhook')) {
            return null;
        }

        if ($isMultipart) {
            $multipartSchema = [
                'type' => 'object',
                'properties' => $schema['properties'],
                'additionalProperties' => true,
            ];
            if (!empty($schema['required'])) {
                $multipartSchema['required'] = $schema['required'];
            }

            return [
                'required' => true,
                'content' => [
                    'multipart/form-data' => [
                        'schema' => [
                            ...$multipartSchema,
                        ],
                    ],
                ],
            ];
        }

        $jsonSchema = [
            'type' => 'object',
            'properties' => $schema['properties'],
            'additionalProperties' => true,
        ];
        if (!empty($schema['required'])) {
            $jsonSchema['required'] = $schema['required'];
        }

        return [
            'required' => true,
            'content' => [
                'application/json' => [
                    'schema' => [
                        ...$jsonSchema,
                    ],
                ],
            ],
        ];
    }

    private function looksLikeMultipart(string $uri, string $action): bool
    {
        $lower = strtolower($uri . ' ' . $action);

        return str_contains($lower, 'upload')
            || str_contains($lower, 'avatar')
            || str_contains($lower, 'attachment')
            || str_contains($lower, 'files/');
    }

    /**
     * @return array<string, string>
     */
    private function extractValidationRulesFromAction(string $action): array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return [];
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $rulesArray = $this->extractFirstValidationRulesArrayLiteral($source);
        if (!is_string($rulesArray) || $rulesArray === '') {
            // Common pattern: `$rules = [ ... ]; Validator::make($input, $rules)`
            $rulesArray = $this->extractAssignedRulesArrayLiteral($source, '$rules');
        }
        if (!is_string($rulesArray) || $rulesArray === '') {
            return [];
        }

        return $this->parseSimpleStringRulesFromArrayLiteral($rulesArray);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function parseControllerAction(string $action): array
    {
        if ($action === 'Closure' || !str_contains($action, '@')) {
            return [null, null];
        }

        [$class, $method] = explode('@', $action, 2);

        return [$class ?: null, $method ?: null];
    }

    private function getMethodSource(string $class, string $method): ?string
    {
        $cacheKey = $class . '@' . $method;

        if (array_key_exists($cacheKey, $this->methodSourceCache)) {
            return $this->methodSourceCache[$cacheKey];
        }

        try {
            $ref = new \ReflectionMethod($class, $method);
        } catch (\ReflectionException $e) {
            $this->methodSourceCache[$cacheKey] = null;
            return null;
        }

        $file = $ref->getFileName();
        if (!is_string($file) || $file === '' || !File::exists($file)) {
            $this->methodSourceCache[$cacheKey] = null;
            return null;
        }

        $startLine = $ref->getStartLine();
        $endLine = $ref->getEndLine();
        if (!is_int($startLine) || !is_int($endLine) || $startLine <= 0 || $endLine < $startLine) {
            $this->methodSourceCache[$cacheKey] = null;
            return null;
        }

        $lines = preg_split('/\R/', File::get($file));
        if (!is_array($lines)) {
            $this->methodSourceCache[$cacheKey] = null;
            return null;
        }

        $slice = array_slice($lines, $startLine - 1, $endLine - $startLine + 1);
        $source = implode("\n", $slice);

        $this->methodSourceCache[$cacheKey] = $source;

        return $source;
    }

    private function extractFirstValidationRulesArrayLiteral(string $source): ?string
    {
        $candidates = [
            'Validator::make',
            '->validate',
        ];

        foreach ($candidates as $candidate) {
            $pos = strpos($source, $candidate);
            if ($pos === false) {
                continue;
            }

            $arrayStart = strpos($source, '[', $pos);
            if ($arrayStart === false) {
                continue;
            }

            $arrayLiteral = $this->extractBracketedSubstring($source, $arrayStart, '[', ']');
            if (is_string($arrayLiteral) && $arrayLiteral !== '') {
                return $arrayLiteral;
            }
        }

        return null;
    }

    private function extractBracketedSubstring(string $source, int $startPos, string $open, string $close): ?string
    {
        $len = strlen($source);
        $depth = 0;
        $inSingle = false;
        $inDouble = false;
        $realStart = null;

        for ($i = $startPos; $i < $len; $i++) {
            $ch = $source[$i];

            if ($ch === "'" && !$inDouble && ($i === 0 || $source[$i - 1] !== '\\')) {
                $inSingle = !$inSingle;
            } elseif ($ch === '"' && !$inSingle && ($i === 0 || $source[$i - 1] !== '\\')) {
                $inDouble = !$inDouble;
            }

            if ($inSingle || $inDouble) {
                continue;
            }

            if ($ch === $open) {
                if ($depth === 0) {
                    $realStart = $i;
                }
                $depth++;
            } elseif ($ch === $close) {
                $depth--;
                if ($depth === 0 && $realStart !== null) {
                    return substr($source, $realStart, $i - $realStart + 1);
                }
            }
        }

        return null;
    }

    private function extractAssignedRulesArrayLiteral(string $source, string $variable): ?string
    {
        $pos = strpos($source, $variable);
        if ($pos === false) {
            return null;
        }

        $eq = strpos($source, '=', $pos);
        if ($eq === false) {
            return null;
        }

        $arrayStart = strpos($source, '[', $eq);
        if ($arrayStart === false) {
            return null;
        }

        return $this->extractBracketedSubstring($source, $arrayStart, '[', ']');
    }

    /**
     * @param string $arrayLiteral Example: ['email' => 'required|email']
     * @return array<string, string>
     */
    private function parseSimpleStringRulesFromArrayLiteral(string $arrayLiteral): array
    {
        $rules = [];

        preg_match_all(
            "/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>\\s*['\\\"](?<value>[^'\\\"]*)['\\\"]/m",
            $arrayLiteral,
            $matches,
            PREG_SET_ORDER
        );

        foreach ($matches as $m) {
            $key = $m['key'] ?? null;
            $value = $m['value'] ?? null;
            if (!is_string($key) || $key === '' || !is_string($value) || $value === '') {
                continue;
            }

            $rules[$key] = $value;
        }

        return $rules;
    }

    /**
     * @param array<string, string> $rules
     */
    private function rulesContainFileUpload(array $rules): bool
    {
        foreach ($rules as $rule) {
            $lower = strtolower($rule);
            if (str_contains($lower, 'image') || str_contains($lower, 'file') || str_contains($lower, 'mimes:')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, string> $rules
     * @return array{properties: array<string, mixed>, required: array<int, string>}
     */
    private function buildSchemaFromValidationRules(array $rules, bool $multipart): array
    {
        if (empty($rules)) {
            $fallbackProperties = $multipart
                ? ['file' => ['type' => 'string', 'format' => 'binary']]
                : new \stdClass();

            return [
                'properties' => $fallbackProperties,
                'required' => [],
            ];
        }

        $properties = [];
        $required = [];

        foreach ($rules as $field => $rule) {
            $schema = $this->schemaForRuleString($rule);

            // Heuristic: Laravel often uses `numeric` for *_id fields; model IDs are integers.
            if (
                ($schema['type'] ?? null) === 'number'
                && is_string($field)
                && ($field === 'id' || str_ends_with($field, '_id'))
                && !isset($schema['multipleOf'])
            ) {
                $schema['type'] = 'integer';
                $schema['format'] = 'int64';
            }

            $properties[$field] = $schema;

            if ($this->isRuleRequired($rule)) {
                $required[] = $field;
            }
        }

        sort($required);

        $result = [
            'properties' => $properties,
            'required' => $required,
        ];

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function schemaForRuleString(string $rule): array
    {
        $parts = $this->splitRuleString($rule);
        $names = array_map(fn (string $p) => $this->ruleName($p), $parts);

        $schema = [
            'type' => 'string',
            // Keep the full Laravel rule string for completeness.
            'x-laravel-rules' => $parts,
        ];

        // File uploads (Laravel file/image/mimes/mimetypes)
        $isFile = $this->isFileRuleParts($parts)
            || $this->hasRule($names, 'mimes')
            || $this->hasRule($names, 'mimetypes');
        if ($isFile) {
            $schema = [
                'type' => 'string',
                'format' => 'binary',
                'x-laravel-rules' => $parts,
            ];

            $mimes = $this->getRuleParam($parts, 'mimes');
            if (is_string($mimes) && $mimes !== '') {
                $schema['x-file-mimes'] = array_values(array_filter(array_map('trim', explode(',', $mimes)), fn ($v) => $v !== ''));
            }
            $mimetypes = $this->getRuleParam($parts, 'mimetypes');
            if (is_string($mimetypes) && $mimetypes !== '') {
                $schema['x-file-mimetypes'] = array_values(array_filter(array_map('trim', explode(',', $mimetypes)), fn ($v) => $v !== ''));
            }
        } elseif ($this->hasRule($names, 'uuid')) {
            $schema = ['type' => 'string', 'format' => 'uuid', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'url')) {
            $schema = ['type' => 'string', 'format' => 'uri', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'email')) {
            $schema = ['type' => 'string', 'format' => 'email', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'boolean')) {
            $schema = ['type' => 'boolean', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'integer')) {
            $schema = ['type' => 'integer', 'format' => 'int64', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'numeric')) {
            $schema = ['type' => 'number', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'array')) {
            $schema = ['type' => 'array', 'items' => new \stdClass(), 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'json')) {
            $schema = ['type' => 'object', 'additionalProperties' => true, 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'date_format')) {
            $format = $this->getRuleParam($parts, 'date_format');
            $isDateTime = is_string($format) && (str_contains($format, 'H') || str_contains($format, 'h') || str_contains($format, 'i') || str_contains($format, 's'));
            $schema = [
                'type' => 'string',
                'format' => $isDateTime ? 'date-time' : 'date',
                'x-laravel-rules' => $parts,
                'x-laravel-date-format' => $format,
            ];
        } elseif ($this->hasRule($names, 'datetime')) {
            $schema = ['type' => 'string', 'format' => 'date-time', 'x-laravel-rules' => $parts];
        } elseif ($this->hasRule($names, 'date')) {
            $schema = ['type' => 'string', 'format' => 'date', 'x-laravel-rules' => $parts];
        }

        // Digits rules
        if ($this->hasRule($names, 'digits')) {
            $digits = $this->getRuleParam($parts, 'digits');
            if (is_string($digits) && is_numeric($digits)) {
                $n = (int)$digits;
                $schema = array_merge($schema, [
                    'type' => 'string',
                    'pattern' => '^\\d{' . $n . '}$',
                    'minLength' => $n,
                    'maxLength' => $n,
                ]);
            }
        } elseif ($this->hasRule($names, 'digits_between')) {
            $between = $this->getRuleParam($parts, 'digits_between');
            if (is_string($between) && str_contains($between, ',')) {
                [$a, $b] = array_map('trim', explode(',', $between, 2));
                if (is_numeric($a) && is_numeric($b)) {
                    $min = (int)$a;
                    $max = (int)$b;
                    $schema = array_merge($schema, [
                        'type' => 'string',
                        'pattern' => '^\\d{' . $min . ',' . $max . '}$',
                        'minLength' => $min,
                        'maxLength' => $max,
                    ]);
                }
            }
        }

        // Regex rule (best-effort mapping; Laravel uses PCRE)
        if ($this->hasRule($names, 'regex')) {
            $regex = $this->getRuleParam($parts, 'regex');
            if (is_string($regex) && $regex !== '') {
                $schema['pattern'] = $this->stripRegexDelimiters($regex);
                $schema['x-laravel-regex'] = $regex;
            }
        }

        // Alpha rules
        if ($this->hasRule($names, 'alpha')) {
            $schema['pattern'] = '^[A-Za-z]+$';
        } elseif ($this->hasRule($names, 'alpha_num')) {
            $schema['pattern'] = '^[A-Za-z0-9]+$';
        } elseif ($this->hasRule($names, 'alpha_dash')) {
            $schema['pattern'] = '^[A-Za-z0-9_-]+$';
        }

        // Nullable
        if ($this->hasRule($names, 'nullable')) {
            $schema['nullable'] = true;
        }

        // Decimal rule (Laravel 12): decimal:2 or decimal:min,max
        if ($this->hasRule($names, 'decimal')) {
            $dec = $this->getRuleParam($parts, 'decimal');
            if (is_string($dec) && $dec !== '') {
                $schema['type'] = 'number';
                $maxDecimals = null;
                if (str_contains($dec, ',')) {
                    [, $maxD] = array_map('trim', explode(',', $dec, 2));
                    if (is_numeric($maxD)) {
                        $maxDecimals = (int)$maxD;
                    }
                } elseif (is_numeric($dec)) {
                    $maxDecimals = (int)$dec;
                }
                if (is_int($maxDecimals) && $maxDecimals >= 0) {
                    $schema['multipleOf'] = (float)('0.' . str_repeat('0', max(0, $maxDecimals - 1)) . '1');
                }
            }
        }

        // Common constraints (max/min/size/in/between)
        $schema = $this->applyConstraintsFromRuleParts($schema, $parts);

        // File constraints (max/min/size on file rules are KB)
        if (($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) === 'binary') {
            $maxKb = $this->getRuleParam($parts, 'max');
            if (is_string($maxKb) && is_numeric($maxKb)) {
                $schema['x-file-max-kb'] = (int)$maxKb;
            }
            $minKb = $this->getRuleParam($parts, 'min');
            if (is_string($minKb) && is_numeric($minKb)) {
                $schema['x-file-min-kb'] = (int)$minKb;
            }
            $sizeKb = $this->getRuleParam($parts, 'size');
            if (is_string($sizeKb) && is_numeric($sizeKb)) {
                $schema['x-file-size-kb'] = (int)$sizeKb;
            }
        }

        // Helpful vendor extensions for DB-backed rules.
        $exists = $this->getRuleParam($parts, 'exists');
        if (is_string($exists) && $exists !== '') {
            $schema['x-laravel-exists'] = $exists;
        }
        $unique = $this->getRuleParam($parts, 'unique');
        if (is_string($unique) && $unique !== '') {
            $schema['x-laravel-unique'] = $unique;
        }

        return $schema;
    }

    private function isRuleRequired(string $rule): bool
    {
        $parts = $this->splitRuleString($rule);
        $names = array_map(fn (string $p) => $this->ruleName($p), $parts);

        // Optional unless present (common Laravel pattern)
        if ($this->hasRule($names, 'sometimes')) {
            return false;
        }

        foreach ($names as $name) {
            if ($name === 'required' || str_starts_with($name, 'required_')) {
                return true;
            }
            if ($name === 'present' || $name === 'accepted' || $name === 'accepted_if') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $schema
     * @param array<int, string> $parts
     * @return array<string, mixed>
     */
    private function applyConstraintsFromRuleParts(array $schema, array $parts): array
    {
        if (($schema['type'] ?? null) === 'string' && ($schema['format'] ?? null) === 'binary') {
            // File rules use `max` as filesize (KB), which doesn't map cleanly to OpenAPI string length.
            return $schema;
        }

        $type = $schema['type'] ?? 'string';

        $max = $this->getRuleParam($parts, 'max');
        $min = $this->getRuleParam($parts, 'min');
        $size = $this->getRuleParam($parts, 'size');
        $between = $this->getRuleParam($parts, 'between');
        $in = $this->getRuleParam($parts, 'in');

        if (is_string($in) && $in !== '') {
            $schema['enum'] = array_values(array_filter(array_map('trim', explode(',', $in)), fn ($v) => $v !== ''));
        }

        if (is_string($between) && str_contains($between, ',')) {
            [$a, $b] = array_map('trim', explode(',', $between, 2));
            if ($type === 'string') {
                if (is_numeric($a)) {
                    $schema['minLength'] = (int)$a;
                }
                if (is_numeric($b)) {
                    $schema['maxLength'] = (int)$b;
                }
            } elseif ($type === 'integer' || $type === 'number') {
                if (is_numeric($a)) {
                    $schema['minimum'] = ($type === 'integer') ? (int)$a : (float)$a;
                }
                if (is_numeric($b)) {
                    $schema['maximum'] = ($type === 'integer') ? (int)$b : (float)$b;
                }
            } elseif ($type === 'array') {
                if (is_numeric($a)) {
                    $schema['minItems'] = (int)$a;
                }
                if (is_numeric($b)) {
                    $schema['maxItems'] = (int)$b;
                }
            }
        }

        if (is_string($size) && is_numeric($size)) {
            if ($type === 'string') {
                $schema['minLength'] = (int)$size;
                $schema['maxLength'] = (int)$size;
            } elseif ($type === 'integer') {
                $schema['minimum'] = (int)$size;
                $schema['maximum'] = (int)$size;
            } elseif ($type === 'number') {
                $schema['minimum'] = (float)$size;
                $schema['maximum'] = (float)$size;
            } elseif ($type === 'array') {
                $schema['minItems'] = (int)$size;
                $schema['maxItems'] = (int)$size;
            }
        }

        if (is_string($min) && is_numeric($min)) {
            if ($type === 'string') {
                $schema['minLength'] = (int)$min;
            } elseif ($type === 'integer') {
                $schema['minimum'] = (int)$min;
            } elseif ($type === 'number') {
                $schema['minimum'] = (float)$min;
            } elseif ($type === 'array') {
                $schema['minItems'] = (int)$min;
            }
        }

        if (is_string($max) && is_numeric($max)) {
            if ($type === 'string') {
                $schema['maxLength'] = (int)$max;
            } elseif ($type === 'integer') {
                $schema['maximum'] = (int)$max;
            } elseif ($type === 'number') {
                $schema['maximum'] = (float)$max;
            } elseif ($type === 'array') {
                $schema['maxItems'] = (int)$max;
            }
        }

        return $schema;
    }

    /**
     * @return array<int, string>
     */
    private function splitRuleString(string $rule): array
    {
        $parts = array_map('trim', explode('|', $rule));
        return array_values(array_filter($parts, fn ($p) => $p !== ''));
    }

    /**
     * @param array<int, string> $parts
     */
    private function isFileRuleParts(array $parts): bool
    {
        foreach ($parts as $p) {
            $name = $this->ruleName($p);
            if ($name === 'file' || $name === 'image') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<int, string> $parts
     */
    private function getRuleParam(array $parts, string $name): ?string
    {
        $needle = strtolower($name);

        foreach ($parts as $p) {
            if ($this->ruleName($p) === $needle) {
                return $this->ruleParam($p);
            }
        }

        return null;
    }

    private function ruleName(string $part): string
    {
        $pos = strpos($part, ':');
        $name = $pos === false ? $part : substr($part, 0, $pos);
        return strtolower(trim($name));
    }

    private function ruleParam(string $part): ?string
    {
        $pos = strpos($part, ':');
        if ($pos === false) {
            return null;
        }

        return substr($part, $pos + 1);
    }

    /**
     * @param array<int, string> $names
     */
    private function hasRule(array $names, string $ruleName): bool
    {
        return in_array(strtolower($ruleName), $names, true);
    }

    private function stripRegexDelimiters(string $regex): string
    {
        $regex = trim($regex);
        // Typical Laravel regex is like: /pattern/flags
        if (strlen($regex) >= 2 && ($regex[0] === '/' || $regex[0] === '#')) {
            $delim = $regex[0];
            $last = strrpos($regex, $delim);
            if ($last !== false && $last > 0) {
                $body = substr($regex, 1, $last - 1);
                return $body !== '' ? $body : $regex;
            }
        }

        return $regex;
    }

    /**
     * @param mixed $properties
     */
    private function schemaContainsBinary($properties): bool
    {
        if (!is_array($properties)) {
            return false;
        }

        foreach ($properties as $fieldSchema) {
            if (!is_array($fieldSchema)) {
                continue;
            }
            if (($fieldSchema['type'] ?? null) === 'string' && ($fieldSchema['format'] ?? null) === 'binary') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array{properties: mixed, required: array<int, string>} $schema
     */
    private function schemaHasNoExplicitProperties(array $schema): bool
    {
        $props = $schema['properties'] ?? null;

        if ($props instanceof \stdClass) {
            return true;
        }

        if (is_array($props) && count($props) === 0) {
            return true;
        }

        return false;
    }

    /**
     * Infer request body schema from the Model that corresponds to the controller (ControllerName -> ModelName).
     *
     * @return array{properties: array<string, mixed>, required: array<int, string>}|null
     */
    private function buildSchemaFromModelContext(Route $route, string $httpMethod, string $action): ?array
    {
        $modelClass = $this->inferModelClassFromAction($action);
        if ($modelClass === null) {
            return null;
        }

        try {
            /** @var EloquentModel $model */
            $model = new $modelClass();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$model instanceof EloquentModel) {
            return null;
        }

        [, $methodName] = $this->parseControllerAction($action);
        $includeRequired = ($httpMethod === 'POST' && $methodName === 'store'); // updates are typically partial in this codebase

        $cacheKey = $modelClass . '|' . ($includeRequired ? 'create' : 'update');
        if (array_key_exists($cacheKey, $this->modelSchemaCache)) {
            return $this->modelSchemaCache[$cacheKey];
        }

        $fillable = $model->getFillable();
        $casts = $model->getCasts();
        $table = $model->getTable();

        $rules = $this->getModelRules($modelClass);

        $fields = array_values(array_unique(array_merge($fillable, array_keys($rules))));
        $fields = array_values(array_filter($fields, fn ($f) => is_string($f) && $f !== ''));

        if (empty($fields)) {
            return null;
        }

        $properties = [];
        $required = [];

        foreach ($fields as $field) {
            $rule = $rules[$field] ?? null;
            $cast = $casts[$field] ?? null;
            $dbMeta = $this->getDatabaseColumnMeta($table, $field);

            $properties[$field] = $this->schemaForFieldUsingModelContext($field, $rule, $cast, $dbMeta);

            if ($includeRequired && $this->shouldBeRequiredForCreate($field, $rule, $dbMeta)) {
                $required[] = $field;
            }
        }

        sort($required);

        $result = [
            'properties' => $properties,
            'required' => $required,
        ];

        $this->modelSchemaCache[$cacheKey] = $result;

        return $result;
    }

    private function inferModelClassFromAction(string $action): ?string
    {
        [$controllerClass, ] = $this->parseControllerAction($action);
        if ($controllerClass === null) {
            return null;
        }

        $controllerBase = class_basename($controllerClass);
        if (!str_ends_with($controllerBase, 'Controller')) {
            return null;
        }

        $modelBase = substr($controllerBase, 0, -strlen('Controller'));
        if ($modelBase === '') {
            return null;
        }

        // Special-case mappings where controller name doesn't match a model name.
        $modelAliases = [
            'Me' => 'User',
        ];
        if (isset($modelAliases[$modelBase])) {
            $modelBase = $modelAliases[$modelBase];
        }

        $modelClass = 'App\\Models\\' . $modelBase;
        if (!class_exists($modelClass)) {
            return null;
        }

        return $modelClass;
    }

    /**
     * @return array<string, string>
     */
    private function getModelRules(string $modelClass): array
    {
        if (!method_exists($modelClass, 'getRules')) {
            return [];
        }

        try {
            $rules = $modelClass::getRules(null);
        } catch (\Throwable $e) {
            return [];
        }

        if (!is_array($rules)) {
            return [];
        }

        $out = [];
        foreach ($rules as $field => $rule) {
            if (!is_string($field) || $field === '') {
                continue;
            }

            if (is_string($rule) && $rule !== '') {
                $out[$field] = $rule;
            } elseif (is_array($rule)) {
                $ruleParts = [];
                foreach ($rule as $r) {
                    if (is_string($r) && $r !== '') {
                        $ruleParts[] = $r;
                    }
                }
                if (!empty($ruleParts)) {
                    $out[$field] = implode('|', $ruleParts);
                }
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed>|null $dbMeta
     * @return array<string, mixed>
     */
    private function schemaForFieldUsingModelContext(string $field, ?string $rule, ?string $cast, ?array $dbMeta): array
    {
        $schema = [];

        $hasExplicitTypeInRules = false;
        if (is_string($rule) && $rule !== '') {
            $schema = $this->schemaForRuleString($rule);
            $hasExplicitTypeInRules = $this->ruleDeclaresType($rule);
        }

        $castSchema = $this->schemaFromCast($cast);
        $dbSchema = $this->schemaFromDbMeta($dbMeta);

        // Prefer rule-declared types; otherwise fall back to casts, then DB.
        if (!$hasExplicitTypeInRules) {
            if ($castSchema !== null) {
                $schema = array_merge($schema, $castSchema);
            } elseif ($dbSchema !== null) {
                $schema = array_merge($schema, $dbSchema);
            } else {
                // Last resort guess based on field name
                if (str_ends_with($field, '_id') || $field === 'id') {
                    $schema = array_merge($schema, ['type' => 'integer', 'format' => 'int64']);
                } else {
                    $schema = array_merge($schema, ['type' => 'string']);
                }
            }
        }

        // If DB says varchar length and we don't have maxLength already, apply it.
        if (($schema['type'] ?? null) === 'string' && !isset($schema['maxLength'])) {
            $len = $dbMeta['length'] ?? null;
            if (is_int($len) && $len > 0) {
                $schema['maxLength'] = $len;
            }
        }

        // Nullable: rules win, else DB.
        if (!isset($schema['nullable'])) {
            $notNull = $dbMeta['notnull'] ?? null;
            if ($notNull === false) {
                $schema['nullable'] = true;
            }
        }

        // Heuristic: treat *_id numeric fields as integers in schemas/examples.
        if (
            ($schema['type'] ?? null) === 'number'
            && ($field === 'id' || str_ends_with($field, '_id'))
            && !isset($schema['multipleOf'])
        ) {
            $schema['type'] = 'integer';
            $schema['format'] = 'int64';
        }

        return $schema;
    }

    private function ruleDeclaresType(string $rule): bool
    {
        $lower = strtolower($rule);
        $needles = [
            'string',
            'integer',
            'numeric',
            'boolean',
            'array',
            'date',
            'datetime',
            'date_format',
            'email',
            'file',
            'image',
        ];

        foreach ($needles as $n) {
            if (str_contains($lower, $n)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function schemaFromCast(?string $cast): ?array
    {
        if (!is_string($cast) || $cast === '') {
            return null;
        }

        $cast = strtolower($cast);

        return match (true) {
            in_array($cast, ['int', 'integer'], true) => ['type' => 'integer', 'format' => 'int64'],
            in_array($cast, ['real', 'float', 'double', 'decimal'], true) => ['type' => 'number'],
            in_array($cast, ['bool', 'boolean'], true) => ['type' => 'boolean'],
            in_array($cast, ['date'], true) => ['type' => 'string', 'format' => 'date'],
            str_contains($cast, 'datetime') => ['type' => 'string', 'format' => 'date-time'],
            in_array($cast, ['array', 'json', 'collection'], true) => ['type' => 'object', 'additionalProperties' => true],
            default => null,
        };
    }

    /**
     * @param array<string, mixed>|null $dbMeta
     * @return array<string, mixed>|null
     */
    private function schemaFromDbMeta(?array $dbMeta): ?array
    {
        if (!is_array($dbMeta) || !isset($dbMeta['type'])) {
            return null;
        }

        $type = (string)$dbMeta['type'];

        return match ($type) {
            'integer', 'bigint', 'smallint' => ['type' => 'integer', 'format' => 'int64'],
            'decimal', 'float' => ['type' => 'number'],
            'boolean' => ['type' => 'boolean'],
            'date' => ['type' => 'string', 'format' => 'date'],
            'datetime', 'datetimetz', 'timestamp' => ['type' => 'string', 'format' => 'date-time'],
            'json' => ['type' => 'object', 'additionalProperties' => true],
            default => ['type' => 'string'],
        };
    }

    /**
     * @return array<string, mixed>|null
     */
    private function getDatabaseColumnMeta(string $table, string $column): ?array
    {
        if (isset($this->dbColumnMetaCache[$table]) && array_key_exists($column, $this->dbColumnMetaCache[$table])) {
            return $this->dbColumnMetaCache[$table][$column];
        }

        try {
            if (!Schema::hasColumn($table, $column)) {
                $this->dbColumnMetaCache[$table][$column] = null;
                return null;
            }
        } catch (\Throwable $e) {
            $this->dbColumnMetaCache[$table][$column] = null;
            return null;
        }

        try {
            $doctrineColumn = DB::connection()->getDoctrineColumn($table, $column);
        } catch (\Throwable $e) {
            $this->dbColumnMetaCache[$table][$column] = null;
            return null;
        }

        try {
            $type = $doctrineColumn->getType()->getName();
        } catch (\Throwable $e) {
            $type = 'string';
        }

        $meta = [
            'type' => $type,
            'length' => method_exists($doctrineColumn, 'getLength') ? $doctrineColumn->getLength() : null,
            'precision' => method_exists($doctrineColumn, 'getPrecision') ? $doctrineColumn->getPrecision() : null,
            'scale' => method_exists($doctrineColumn, 'getScale') ? $doctrineColumn->getScale() : null,
            'notnull' => method_exists($doctrineColumn, 'getNotnull') ? $doctrineColumn->getNotnull() : null,
            'default' => method_exists($doctrineColumn, 'getDefault') ? $doctrineColumn->getDefault() : null,
            'autoincrement' => method_exists($doctrineColumn, 'getAutoincrement') ? $doctrineColumn->getAutoincrement() : null,
        ];

        $this->dbColumnMetaCache[$table][$column] = $meta;

        return $meta;
    }

    /**
     * @param array<string, mixed>|null $dbMeta
     */
    private function shouldBeRequiredForCreate(string $field, ?string $rule, ?array $dbMeta): bool
    {
        if (in_array($field, ['id', 'created_at', 'updated_at', 'deleted_at'], true)) {
            return false;
        }

        if (is_string($rule) && $rule !== '') {
            return $this->isRuleRequired($rule);
        }

        if (!is_array($dbMeta)) {
            return false;
        }

        $notNull = $dbMeta['notnull'] ?? null;
        $default = $dbMeta['default'] ?? null;
        $auto = $dbMeta['autoincrement'] ?? null;

        if ($auto === true) {
            return false;
        }

        return ($notNull === true) && ($default === null);
    }

    /**
     * @return array<int, string>
     */
    private function extractQueryParamNamesFromAction(string $action): array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return [];
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $names = [];

        // request('param')
        preg_match_all("/\\brequest\\(\\s*['\\\"](?<name>[^'\\\"]+)['\\\"]/m", $source, $m1);
        if (isset($m1['name']) && is_array($m1['name'])) {
            foreach ($m1['name'] as $n) {
                if (is_string($n) && $n !== '') {
                    $names[$n] = true;
                }
            }
        }

        // $request->get('param') / input / query
        preg_match_all('/\\$request->(?:get|input|query)\\(\\s*[\\\'\\"](?<name>[^\\\'\\"]+)[\\\'\\"]/m', $source, $m2);
        if (isset($m2['name']) && is_array($m2['name'])) {
            foreach ($m2['name'] as $n) {
                if (is_string($n) && $n !== '') {
                    $names[$n] = true;
                }
            }
        }

        $result = array_keys($names);
        sort($result);

        return $result;
    }

    /**
     * Extract file field names used in controller method (e.g. hasFile('file'), file('avatar')).
     *
     * @return array<int, string>
     */
    private function extractFileFieldNamesFromAction(string $action): array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return [];
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $names = [];

        preg_match_all("/->hasFile\\(\\s*['\\\"](?<name>[^'\\\"]+)['\\\"]\\s*\\)/m", $source, $m1);
        if (isset($m1['name']) && is_array($m1['name'])) {
            foreach ($m1['name'] as $n) {
                if (is_string($n) && $n !== '') {
                    $names[$n] = true;
                }
            }
        }

        preg_match_all("/->file\\(\\s*['\\\"](?<name>[^'\\\"]+)['\\\"]\\s*\\)/m", $source, $m2);
        if (isset($m2['name']) && is_array($m2['name'])) {
            foreach ($m2['name'] as $n) {
                if (is_string($n) && $n !== '') {
                    $names[$n] = true;
                }
            }
        }

        $result = array_keys($names);
        sort($result);

        return $result;
    }

    /**
     * Extract array keys accessed on a given variable (e.g. $data['invoiceId']).
     *
     * @return array<int, string>
     */
    private function extractArrayKeysFromAction(string $action, string $variableName): array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return [];
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return [];
        }

        $names = [];
        $var = preg_quote($variableName, '/');

        preg_match_all("/{$var}\\s*\\[\\s*['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*\\]/m", $source, $m);
        if (isset($m['key']) && is_array($m['key'])) {
            foreach ($m['key'] as $k) {
                if (is_string($k) && $k !== '') {
                    $names[$k] = true;
                }
            }
        }

        $result = array_keys($names);
        sort($result);

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    private function guessSchemaForWebhookKey(string $key): array
    {
        $lower = strtolower($key);

        if (str_contains($lower, 'amount') || str_contains($lower, 'sum')) {
            return ['type' => 'number'];
        }

        if (str_ends_with($lower, 'id') || str_contains($lower, 'uuid')) {
            return ['type' => 'string'];
        }

        if (str_contains($lower, 'status')) {
            return ['type' => 'string'];
        }

        return ['type' => 'string'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function buildResponses(Route $route, string $httpMethod, string $path, string $action, ?string $routeName): array
    {
        $responses = [];

        if (str_contains($path, '/download') || str_contains($path, '/preview')) {
            $contentType = (str_contains($path, 'invoice') || str_contains($path, 'certificate'))
                ? 'application/pdf'
                : 'application/octet-stream';

            $responses['200'] = [
                'description' => 'File download',
                'content' => [
                    $contentType => [
                        'schema' => ['type' => 'string', 'format' => 'binary'],
                    ],
                ],
            ];
        } else {
            $successExample = $this->buildSuccessExample($route, $httpMethod, $path, $action, $routeName);
            $exampleName = $this->successExampleName($route, $httpMethod, $path, $action, $routeName);
            $this->componentsExamples[$exampleName] = [
                'summary' => 'Example successful response for ' . $httpMethod . ' ' . $path,
                'value' => $successExample,
            ];

            $responses['200'] = [
                'description' => 'OK',
                'content' => [
                    'application/json' => [
                        'schema' => ['$ref' => '#/components/schemas/ApiSuccess'],
                        'examples' => [
                            'default' => [
                                '$ref' => '#/components/examples/' . $exampleName,
                            ],
                        ],
                    ],
                ],
            ];
        }

        $jsonError = [
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/ApiError'],
                    'example' => [
                        'success' => false,
                        'message' => 'Error',
                        'data' => new \stdClass(),
                    ],
                ],
            ],
        ];

        $responses['400'] = ['description' => 'Bad Request'] + $jsonError;
        $responses['403'] = ['description' => 'Forbidden'] + $jsonError;
        $responses['404'] = ['description' => 'Not Found'] + $jsonError;
        $responses['422'] = ['description' => 'Validation Error'] + $jsonError;

        $responses['401'] = [
            'description' => 'Unauthenticated',
            'content' => [
                'application/json' => [
                    'schema' => ['$ref' => '#/components/schemas/Unauthenticated'],
                    'example' => ['message' => 'Unauthenticated'],
                ],
            ],
        ];

        return $responses;
    }

    private function successExampleName(Route $route, string $httpMethod, string $path, string $action, ?string $routeName): string
    {
        [$controllerClass, $methodName] = $this->parseControllerAction($action);

        $base = null;
        $modelClass = $this->inferModelClassFromAction($action);
        if (is_string($modelClass)) {
            $base = class_basename($modelClass);
        } elseif (is_string($controllerClass)) {
            $controllerBase = class_basename($controllerClass);
            $base = str_ends_with($controllerBase, 'Controller')
                ? substr($controllerBase, 0, -strlen('Controller'))
                : $controllerBase;
        } else {
            $base = $this->tagFromPath($path);
        }

        $variant = 'Success';

        if ($methodName === 'index' && $httpMethod === 'GET' && empty($route->parameterNames())) {
            $variant = 'SuccessList';
        } elseif ($methodName === 'destroy') {
            $variant = 'SuccessDelete';
        } elseif (!in_array($methodName, ['index', 'store', 'show', 'update', 'destroy', 'create'], true) && is_string($methodName) && $methodName !== '') {
            $variant = 'Success_' . $this->humanizeToKey($methodName);
        }

        $name = $variant . '_' . $this->humanizeToKey((string)$base);

        // Ensure uniqueness for unusual cases
        if (is_string($routeName) && $routeName !== '') {
            $name .= '_' . $this->humanizeToKey($routeName);
        }

        // Component keys must be YAML-safe; keep it simple.
        $name = (string)preg_replace('/_+/', '_', $name);
        $name = trim($name, '_');

        // Cap length
        if (strlen($name) > 120) {
            $name = substr($name, 0, 120);
        }

        return $name;
    }

    private function humanizeToKey(string $value): string
    {
        $value = str_replace(['\\', '/', '.', '-'], '_', $value);
        $value = preg_replace('/[^A-Za-z0-9_]/', '_', $value) ?: $value;
        return (string)preg_replace('/_+/', '_', $value);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSuccessExample(Route $route, string $httpMethod, string $path, string $action, ?string $routeName): array
    {
        $data = $this->buildSuccessDataExample($route, $httpMethod, $path, $action, $routeName);

        return [
            'success' => true,
            'data' => $data,
            'message' => 'OK',
        ];
    }

    /**
     * @return mixed
     */
    private function buildSuccessDataExample(Route $route, string $httpMethod, string $path, string $action, ?string $routeName)
    {
        [$controllerClass, $methodName] = $this->parseControllerAction($action);

        // Auth endpoints
        if ($path === '/api/register' && $httpMethod === 'POST') {
            return [
                'token' => $this->fakeToken(),
                'name' => $this->fakeName(),
            ];
        }
        if ($path === '/api/login' && $httpMethod === 'POST') {
            return [
                'token' => $this->fakeToken(),
                'name' => $this->fakeName(),
                'requires_mfa' => false,
            ];
        }

        // Standard resource patterns
        if ($methodName === 'index' && $httpMethod === 'GET' && empty($route->parameterNames())) {
            $item = $this->buildModelExample($route, $httpMethod, $action);
            return [
                'items' => $item ? [$item] : [],
                'totalItems' => 1,
                'totalPages' => 1,
                'page' => 1,
                'perPage' => 50,
                'order' => 'id:asc',
                'search' => null,
                'filters' => [],
            ];
        }
        if (in_array($methodName, ['store', 'show', 'update'], true)) {
            $item = $this->buildModelExample($route, $httpMethod, $action);
            if (is_array($item)) {
                return $item;
            }
        }
        if ($methodName === 'destroy') {
            return [];
        }

        // Try to infer a simple payload from a sendResponse([...]) array literal
        $responseStructure = $this->extractSendResponseStructureFromAction($action);
        if (!empty($responseStructure)) {
            $example = $this->buildExampleFromStructure($responseStructure);
            if (!empty($example)) {
                return $example;
            }
        }

        // Try to extract structure from variables used in sendResponse (e.g., 'items' => $sortedEvents)
        $variableBasedStructure = $this->extractStructureFromVariables($action);
        if (!empty($variableBasedStructure)) {
            $example = $this->buildExampleFromStructure($variableBasedStructure);
            // If example has empty arrays, enhance with inferred structures
            foreach ($variableBasedStructure as $key => $spec) {
                if (is_array($spec) && ($spec['type'] ?? null) === 'array') {
                    // Ensure array has at least one item
                    $needsItem = !isset($example[$key]) || 
                                 !is_array($example[$key]) || 
                                 empty($example[$key]) ||
                                 (is_object($example[$key]) && $example[$key] instanceof \stdClass);
                    
                    if ($needsItem) {
                        $inferredItem = $this->inferArrayItemStructure($key);
                        if (!empty($inferredItem['properties'] ?? [])) {
                            // Build directly from inferred properties
                            $directExample = [];
                            foreach ($inferredItem['properties'] as $propKey => $propSpec) {
                                $value = $this->fakeValueForSchema($propSpec, $propKey);
                                // Ensure types are correct
                                if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'boolean') {
                                    $value = is_bool($value) ? $value : (bool)$value;
                                }
                                if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'integer') {
                                    $value = is_numeric($value) ? (int)$value : $value;
                                }
                                $directExample[$propKey] = $value;
                            }
                            if (!empty($directExample)) {
                                $example[$key] = [$directExample];
                            }
                        }
                    }
                }
            }
            if (!empty($example)) {
                return $example;
            }
        }

        // Fallback to simple keys extraction
        $keys = $this->extractSendResponseArrayKeysFromAction($action);
        if (!empty($keys)) {
            $result = $this->fakeObjectFromKeys($keys);
            // Enhance with inferred array structures for common keys
            foreach ($keys as $key) {
                $keyLower = strtolower($key);
                // If key suggests an array (items, sessions, etc.), ensure it's an array with proper structure
                if (in_array($keyLower, ['items', 'sessions', 'events', 'activities', 'tokens', 'instructors', 'classmates'])) {
                    $inferredItem = $this->inferArrayItemStructure($key);
                    if (!empty($inferredItem['properties'] ?? [])) {
                        // Build example directly from properties
                        $itemExample = [];
                        foreach ($inferredItem['properties'] as $propKey => $propSpec) {
                            $value = $this->fakeValueForSchema($propSpec, $propKey);
                            // Ensure types are correct
                            if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'boolean') {
                                $value = is_bool($value) ? $value : (bool)$value;
                            }
                            if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'integer') {
                                $value = is_numeric($value) ? (int)$value : $value;
                            }
                            $itemExample[$propKey] = $value;
                        }
                        if (!empty($itemExample)) {
                            $result[$key] = [$itemExample];
                        } else {
                            $result[$key] = [];
                        }
                    } elseif (!isset($result[$key]) || !is_array($result[$key])) {
                        $result[$key] = [];
                    }
                }
            }
            return $result;
        }

        // Fallback to model if available
        $item = $this->buildModelExample($route, $httpMethod, $action);
        if (is_array($item)) {
            return $item;
        }

        // Default empty object
        return new \stdClass();
    }

    private function fakeToken(): string
    {
        if ($this->faker) {
            return $this->faker->sha1;
        }

        return 'example-token';
    }

    private function fakeName(): string
    {
        if ($this->faker) {
            return $this->faker->name;
        }

        return 'John Doe';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildModelExample(Route $route, string $httpMethod, string $action): ?array
    {
        $modelClass = $this->inferModelClassFromAction($action);
        if ($modelClass === null) {
            return null;
        }

        try {
            /** @var EloquentModel $model */
            $model = new $modelClass();
        } catch (\Throwable $e) {
            return null;
        }

        if (!$model instanceof EloquentModel) {
            return null;
        }

        $hidden = method_exists($model, 'getHidden') ? $model->getHidden() : [];
        if (!is_array($hidden)) {
            $hidden = [];
        }
        $hiddenSet = [];
        foreach ($hidden as $h) {
            if (is_string($h) && $h !== '') {
                $hiddenSet[$h] = true;
            }
        }

        $schema = $this->buildSchemaFromModelContext($route, $httpMethod, $action);
        if (!is_array($schema)) {
            return null;
        }

        $properties = $schema['properties'] ?? null;
        if (!is_array($properties) || empty($properties)) {
            return null;
        }

        // Add model class to schema for Faker fallback
        $schema['x-model-class'] = $modelClass;

        // Try OpenAI first (cached per model class + action for context)
        $openAiExample = $this->generateOpenAiExample($route, $httpMethod, $action, $modelClass, $schema, $hiddenSet);
        if (is_array($openAiExample) && !empty($openAiExample)) {
            return $openAiExample;
        }
        $this->info("Falling back to Faker-based example for model {$modelClass} in action {$action}");
        // Fallback to Faker-based generation
        return $this->buildFakerExample($schema, $properties, $hiddenSet);
    }

    /**
     * Generate realistic example using OpenAI (cached per model class + action).
     *
     * @param array<string, mixed> $hiddenSet
     * @return array<string, mixed>|null
     */
    private function generateOpenAiExample(Route $route, string $httpMethod, string $action, string $modelClass, array $schema, array $hiddenSet): ?array
    {
        // Create cache key that includes action for context-specific examples
        $cacheKey = $modelClass . '|' . $action;

        // Check cache first
        if (array_key_exists($cacheKey, $this->openAiExampleCache)) {
            return $this->openAiExampleCache[$cacheKey];
        }

        // Check if OpenAI is available
        if (!class_exists(OpenAIModel::class) || empty(env('OPEN_API_KEY'))) {
            $this->openAiExampleCache[$cacheKey] = null;
            return null;
        }

        try {
            $properties = $schema['properties'] ?? [];
            $required = $schema['required'] ?? [];

            if (empty($properties)) {
                $this->openAiExampleCache[$cacheKey] = null;
                return null;
            }

            // Gather full context
            $context = $this->gatherFullContext($route, $httpMethod, $action, $modelClass);

            // Build sample spec for OpenAI
            $sampleSpec = $this->buildSampleSpecForOpenAi($modelClass, $properties, $required, $hiddenSet);

            // Build prompt with full context
            $prompt = $this->buildOpenAiPromptWithContext($modelClass, $sampleSpec, $context);

            // Call OpenAI (cached via OpenAI::askChatGPTCached)
            $response = OpenAIModel::askChatGPTCached($prompt, true, 60 * 24 * 7); // Cache for 7 days

            if (!$response || !is_object($response)) {
                $this->openAiExampleCache[$cacheKey] = null;
                return null;
            }

            // Convert to array and sanitize
            $example = json_decode(json_encode($response), true);
            if (!is_array($example)) {
                $this->openAiExampleCache[$cacheKey] = null;
                return null;
            }

            $sanitized = $this->sanitizeOpenAiExample($example, $schema, $properties, $required);
            $this->openAiExampleCache[$cacheKey] = $sanitized;

            return $sanitized;
        } catch (\Throwable $e) {
            // Silently fallback to Faker
            $this->openAiExampleCache[$cacheKey] = null;
            return null;
        }
    }

    /**
     * Gather full context: controller method, model, resource, and nested relations.
     *
     * @return array<string, mixed>
     */
    private function gatherFullContext(Route $route, string $httpMethod, string $action, string $modelClass): array
    {
        $context = [
            'controller_method' => null,
            'model' => null,
            'resource' => null,
            'nested_relations' => [],
        ];

        [$controllerClass, $methodName] = $this->parseControllerAction($action);

        // Get controller method source
        if ($controllerClass !== null && $methodName !== null) {
            $methodSource = $this->getMethodSource($controllerClass, $methodName);
            if (is_string($methodSource) && $methodSource !== '') {
                $context['controller_method'] = $methodSource;
            }
        }

        // Get model source
        try {
            $modelReflection = new \ReflectionClass($modelClass);
            $modelFile = $modelReflection->getFileName();
            if ($modelFile && file_exists($modelFile)) {
                $context['model'] = file_get_contents($modelFile);
            }
        } catch (\ReflectionException $e) {
            // Ignore
        }

        // Find Resource class used in controller
        $resourceClass = $this->findResourceClass($controllerClass, $methodName);
        if ($resourceClass !== null) {
            try {
                $resourceReflection = new \ReflectionClass($resourceClass);
                $resourceFile = $resourceReflection->getFileName();
                if ($resourceFile && file_exists($resourceFile)) {
                    $context['resource'] = file_get_contents($resourceFile);
                }
            } catch (\ReflectionException $e) {
                // Ignore
            }
        }

        // Extract nested relations from model
        $context['nested_relations'] = $this->extractNestedRelations($modelClass, $context['controller_method']);

        return $context;
    }

    /**
     * Find Resource class used in controller method.
     */
    private function findResourceClass(?string $controllerClass, ?string $methodName): ?string
    {
        if ($controllerClass === null || $methodName === null) {
            return null;
        }

        $source = $this->getMethodSource($controllerClass, $methodName);
        if (!is_string($source) || $source === '') {
            return null;
        }

        // Look for patterns like: new UserResource($user), UserResource::make($user), new \App\Http\Resources\User($user)
        // Also check for: return new UserResource(...)
        $patterns = [
            '/new\s+(\w+Resource)\s*\(/',
            '/(\w+Resource)::make\s*\(/',
            '/new\s+\\\\App\\\\Http\\\\Resources\\\\(\w+)\s*\(/',
            '/return\s+new\s+(\w+Resource)\s*\(/',
            '/return\s+(\w+Resource)::make\s*\(/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $source, $matches)) {
                $resourceName = $matches[1] ?? null;
                if ($resourceName) {
                    // Try full namespace
                    $fullClass = 'App\\Http\\Resources\\' . $resourceName;
                    if (class_exists($fullClass)) {
                        return $fullClass;
                    }
                    // Try with Resource suffix if not present
                    if (!str_ends_with($resourceName, 'Resource')) {
                        $fullClass = 'App\\Http\\Resources\\' . $resourceName . 'Resource';
                        if (class_exists($fullClass)) {
                            return $fullClass;
                        }
                    }
                }
            }
        }

        // Also check for use statements
        if (preg_match('/use\s+App\\\\Http\\\\Resources\\\\(\w+Resource);/', $source, $matches)) {
            $resourceName = $matches[1] ?? null;
            if ($resourceName) {
                $fullClass = 'App\\Http\\Resources\\' . $resourceName;
                if (class_exists($fullClass)) {
                    return $fullClass;
                }
            }
        }

        return null;
    }

    /**
     * Extract nested relations from model using reflection and controller method.
     *
     * @param string|null $controllerMethodSource
     * @return array<string, array<string, mixed>>
     */
    private function extractNestedRelations(string $modelClass, ?string $controllerMethodSource): array
    {
        $relations = [];
        $eagerLoadedRelations = [];

        // Extract eager-loaded relations from controller method
        if (is_string($controllerMethodSource) && $controllerMethodSource !== '') {
            // Look for ->with(['relation1', 'relation2']) or ->with('relation1', 'relation2')
            if (preg_match_all('/->with\s*\(\s*\[([^\]]+)\]/', $controllerMethodSource, $matches)) {
                foreach ($matches[1] as $withClause) {
                    // Extract relation names from array
                    preg_match_all("/['\"]([^'\"]+)['\"]/", $withClause, $relationMatches);
                    foreach ($relationMatches[1] as $relationName) {
                        $eagerLoadedRelations[$relationName] = true;
                    }
                }
            }
            // Also check for ->with('relation1', 'relation2')
            if (preg_match_all("/->with\s*\(\s*['\"]([^'\"]+)['\"]/", $controllerMethodSource, $matches)) {
                foreach ($matches[1] as $relationName) {
                    $eagerLoadedRelations[$relationName] = true;
                }
            }
            // Check for nested relations like ->with(['relation.subrelation'])
            if (preg_match_all("/->with\s*\(\s*\[([^\]]+)\]/", $controllerMethodSource, $matches)) {
                foreach ($matches[1] as $withClause) {
                    preg_match_all("/['\"]([^'\"]+)['\"]/", $withClause, $relationMatches);
                    foreach ($relationMatches[1] as $relationPath) {
                        // Extract top-level relation
                        $parts = explode('.', $relationPath);
                        if (!empty($parts[0])) {
                            $eagerLoadedRelations[$parts[0]] = true;
                        }
                    }
                }
            }
        }

        try {
            $reflection = new \ReflectionClass($modelClass);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

            foreach ($methods as $method) {
                $methodName = $method->getName();

                // Skip non-relation methods
                if (str_starts_with($methodName, '__') || $methodName === 'getFillable' || $methodName === 'getCasts') {
                    continue;
                }

                // Check return type for relation types
                $returnType = $method->getReturnType();
                if ($returnType instanceof \ReflectionNamedType) {
                    $returnTypeName = $returnType->getName();

                    // Check if it's a relation type
                    if (str_contains($returnTypeName, 'Relation') ||
                        str_contains($returnTypeName, 'HasOne') ||
                        str_contains($returnTypeName, 'HasMany') ||
                        str_contains($returnTypeName, 'BelongsTo') ||
                        str_contains($returnTypeName, 'BelongsToMany') ||
                        str_contains($returnTypeName, 'HasManyThrough')) {

                        // Get method source to understand relation structure
                        $methodSource = $this->getMethodSource($modelClass, $methodName);
                        if (is_string($methodSource) && $methodSource !== '') {
                            // Try to extract related model class
                            $relatedModel = $this->extractRelatedModelFromRelation($methodSource);

                            $relationInfo = [
                                'method_source' => $methodSource,
                                'eager_loaded' => isset($eagerLoadedRelations[$methodName]),
                            ];

                            if ($relatedModel !== null) {
                                $relationInfo['related_model_class'] = $relatedModel;

                                // Get related model source
                                try {
                                    $relatedReflection = new \ReflectionClass($relatedModel);
                                    $relatedFile = $relatedReflection->getFileName();
                                    if ($relatedFile && file_exists($relatedFile)) {
                                        $relationInfo['related_model_source'] = file_get_contents($relatedFile);

                                        // Recursively extract nested relations from related model
                                        $nestedRelations = $this->extractNestedRelations($relatedModel, null);
                                        if (!empty($nestedRelations)) {
                                            $relationInfo['nested_relations'] = $nestedRelations;
                                        }
                                    }
                                } catch (\ReflectionException $e) {
                                    // Ignore
                                }
                            }

                            // Only include if it's eager-loaded or if we want all relations
                            // For now, include all relations but mark which are eager-loaded
                            $relations[$methodName] = $relationInfo;
                        }
                    }
                }
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return $relations;
    }

    /**
     * Extract related model class from relation method source.
     */
    private function extractRelatedModelFromRelation(string $methodSource): ?string
    {
        // Look for patterns like: return $this->hasOne(\App\Models\User::class)
        // or: return $this->hasMany(User::class)
        $patterns = [
            '/hasOne\s*\(\s*\\\\?App\\\\Models\\\\(\w+)::class/',
            '/hasMany\s*\(\s*\\\\?App\\\\Models\\\\(\w+)::class/',
            '/belongsTo\s*\(\s*\\\\?App\\\\Models\\\\(\w+)::class/',
            '/belongsToMany\s*\(\s*\\\\?App\\\\Models\\\\(\w+)::class/',
            '/hasOne\s*\(\s*[\'"]\\\\?App\\\\Models\\\\(\w+)[\'"]/',
            '/hasMany\s*\(\s*[\'"]\\\\?App\\\\Models\\\\(\w+)[\'"]/',
            '/belongsTo\s*\(\s*[\'"]\\\\?App\\\\Models\\\\(\w+)[\'"]/',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $methodSource, $matches)) {
                $modelName = $matches[1] ?? null;
                if ($modelName) {
                    $fullClass = 'App\\Models\\' . $modelName;
                    if (class_exists($fullClass)) {
                        return $fullClass;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Build a compact sample spec for OpenAI prompt.
     *
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     * @param array<string, bool> $hiddenSet
     * @return array<string, mixed>
     */
    private function buildSampleSpecForOpenAi(string $modelClass, array $properties, array $required, array $hiddenSet): array
    {
        $spec = [
            'model_class' => class_basename($modelClass),
            'fields' => [],
        ];

        // Extract model constants for enum-like fields
        $modelConstants = $this->extractModelConstants($modelClass);

        foreach ($properties as $fieldName => $fieldSchema) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            if (isset($hiddenSet[$fieldName])) {
                continue;
            }

            $fieldSpec = [
                'name' => $fieldName,
                'type' => $fieldSchema['type'] ?? 'string',
                'required' => in_array($fieldName, $required, true),
            ];

            if (isset($fieldSchema['format'])) {
                $fieldSpec['format'] = $fieldSchema['format'];
            }

            if (isset($fieldSchema['maxLength'])) {
                $fieldSpec['max_length'] = $fieldSchema['maxLength'];
            }

            if (isset($fieldSchema['minLength'])) {
                $fieldSpec['min_length'] = $fieldSchema['minLength'];
            }

            if (isset($fieldSchema['enum']) && is_array($fieldSchema['enum'])) {
                $fieldSpec['allowed_values'] = array_values($fieldSchema['enum']);
            }

            // Check model constants for this field
            $fieldLower = strtolower($fieldName);

            // Try exact match first
            if (isset($modelConstants[$fieldLower])) {
                $fieldSpec['allowed_values'] = $modelConstants[$fieldLower];
            } else {
                // Try partial matches (e.g., question_type matches QUESTION_TYPE_*)
                foreach ($modelConstants as $constField => $values) {
                    if ($fieldLower === $constField || str_contains($fieldLower, $constField) || str_contains($constField, $fieldLower)) {
                        $fieldSpec['allowed_values'] = $values;
                        break;
                    }
                }
            }

            if (isset($fieldSchema['pattern'])) {
                $fieldSpec['pattern'] = $fieldSchema['pattern'];
            }

            // Include Laravel rules for context
            if (isset($fieldSchema['x-laravel-rules']) && is_array($fieldSchema['x-laravel-rules'])) {
                $fieldSpec['laravel_rules'] = $fieldSchema['x-laravel-rules'];
            }

            $spec['fields'][] = $fieldSpec;
        }

        return $spec;
    }

    /**
     * Extract model constants that look like enum values (STATE_*, STATUS_*, *_TYPE_*).
     *
     * @return array<string, array<int, string>>
     */
    private function extractModelConstants(string $modelClass): array
    {
        $constants = [];

        try {
            $reflection = new \ReflectionClass($modelClass);
            $reflectionConstants = $reflection->getConstants();

            $fieldConstants = [];

            foreach ($reflectionConstants as $constName => $constValue) {
                if (!is_string($constValue)) {
                    continue;
                }

                $constNameLower = strtolower($constName);

                // Match patterns: STATE_*, STATUS_*, *_TYPE_*, etc.
                // Examples: STATE_ACTIVE -> state, STATUS_PENDING -> status, QUESTION_TYPE_MULTIPLE_CHOICE -> question_type
                if (preg_match('/^(state|status|type|account_type|payment_status|enrollment_state|session_state|homework_state|invoice_status|question_type|payment_method)_(.+)$/i', $constNameLower, $matches)) {
                    $fieldKey = $matches[1];
                    if (!isset($fieldConstants[$fieldKey])) {
                        $fieldConstants[$fieldKey] = [];
                    }
                    $fieldConstants[$fieldKey][] = $constValue;
                } elseif (str_starts_with($constNameLower, 'state_')) {
                    if (!isset($fieldConstants['state'])) {
                        $fieldConstants['state'] = [];
                    }
                    $fieldConstants['state'][] = $constValue;
                } elseif (str_starts_with($constNameLower, 'status_')) {
                    if (!isset($fieldConstants['status'])) {
                        $fieldConstants['status'] = [];
                    }
                    $fieldConstants['status'][] = $constValue;
                } elseif (preg_match('/^(.+)_type_(.+)$/i', $constNameLower, $typeMatches)) {
                    // Handle patterns like QUESTION_TYPE_MULTIPLE_CHOICE
                    $fieldKey = $typeMatches[1] . '_type';
                    if (!isset($fieldConstants[$fieldKey])) {
                        $fieldConstants[$fieldKey] = [];
                    }
                    $fieldConstants[$fieldKey][] = $constValue;
                }
            }

            // Map to field names (check both exact match and common variations)
            foreach ($fieldConstants as $fieldKey => $values) {
                $constants[$fieldKey] = array_values(array_unique($values));
                // Also add variations (e.g., 'state' -> 'state', 'status' -> 'status')
                if ($fieldKey === 'state' || $fieldKey === 'status') {
                    $constants[$fieldKey] = array_values(array_unique($values));
                }
            }
        } catch (\ReflectionException $e) {
            // Ignore reflection errors
        }

        return $constants;
    }

    /**
     * Build OpenAI prompt for generating realistic example with full context.
     */
    private function buildOpenAiPromptWithContext(string $modelClass, array $sampleSpec, array $context): string
    {
        $modelName = $sampleSpec['model_class'];
        $currentYear = date('Y');

        $prompt = "You are generating realistic sample JSON data for a Laravel API response example in an OpenAPI specification.\n\n";
        $prompt .= "Model: {$modelName}\n";
        $prompt .= "Current Year: {$currentYear}\n\n";

        // Add controller method context
        if (!empty($context['controller_method'])) {
            $prompt .= "=== CONTROLLER METHOD ===\n";
            $prompt .= "The following is the full controller method that generates this response:\n\n";
            $prompt .= "```php\n" . $context['controller_method'] . "\n```\n\n";
        }

        // Add model context
        if (!empty($context['model'])) {
            $prompt .= "=== MODEL CLASS ===\n";
            $prompt .= "The following is the full model class definition:\n\n";
            $prompt .= "```php\n" . $context['model'] . "\n```\n\n";
        }

        // Add Resource context
        if (!empty($context['resource'])) {
            $prompt .= "=== RESOURCE CLASS ===\n";
            $prompt .= "The following Resource class transforms the model data for the API response:\n\n";
            $prompt .= "```php\n" . $context['resource'] . "\n```\n\n";
        }

        // Add nested relations context
        if (!empty($context['nested_relations'])) {
            $prompt .= "=== NESTED RELATIONS ===\n";
            $prompt .= "The model has the following relations that may be included in the response:\n\n";
            foreach ($context['nested_relations'] as $relationName => $relationInfo) {
                $prompt .= "Relation: {$relationName}\n";
                if (isset($relationInfo['related_model_class'])) {
                    $prompt .= "Related Model: {$relationInfo['related_model_class']}\n";
                }
                if (isset($relationInfo['method_source'])) {
                    $prompt .= "```php\n" . $relationInfo['method_source'] . "\n```\n";
                }
                if (isset($relationInfo['related_model_source'])) {
                    $prompt .= "Related Model Source:\n```php\n" . $relationInfo['related_model_source'] . "\n```\n";
                }
                $prompt .= "\n";
            }
        }

        $prompt .= "=== REQUIREMENTS ===\n";
        $prompt .= "1. Generate realistic, production-like data\n";
        $prompt .= "2. Use English names and text\n";
        $prompt .= "3. For phone numbers, use Ukrainian format: +380XXXXXXXXX (9 digits after +380)\n";
        $prompt .= "4. For email fields, use realistic email addresses\n";
        $prompt .= "5. For date/datetime fields, use dates within {$currentYear}\n";
        $prompt .= "6. For state/status/type fields, choose from the allowed_values if provided\n";
        $prompt .= "7. Respect max_length constraints\n";
        $prompt .= "8. Include all required fields\n";
        $prompt .= "9. For _id fields, use positive integers\n";
        $prompt .= "10. For URLs, use realistic URLs\n";
        $prompt .= "11. For slugs, use URL-friendly lowercase strings with hyphens\n";
        $prompt .= "12. Pay attention to the controller method to understand what data is actually returned\n";
        $prompt .= "13. If a Resource class is provided, follow its toArray() method structure\n";
        $prompt .= "14. Include nested relations if they are loaded in the controller (check ->with() or ->load() calls)\n\n";

        $prompt .= "=== FIELD SPECIFICATIONS ===\n";
        $prompt .= "```json\n" . json_encode($sampleSpec, JSON_PRETTY_PRINT) . "\n```\n\n";

        $prompt .= "Return ONLY a valid JSON object with the field names as keys and realistic sample values.\n";
        $prompt .= "Do not include any explanation, markdown formatting, or code blocks.\n";
        $prompt .= "The response must be valid JSON that can be parsed directly.\n";
        $prompt .= "Example format: {\"id\": 1, \"name\": \"John Doe\", \"email\": \"john.doe@example.com\"}\n";

        return $prompt;
    }

    /**
     * Sanitize OpenAI response to ensure it matches schema constraints.
     *
     * @param array<string, mixed> $example
     * @param array<string, mixed> $properties
     * @param array<int, string> $required
     * @return array<string, mixed>
     */
    private function sanitizeOpenAiExample(array $example, array $schema, array $properties, array $required): array
    {
        $sanitized = [];

        // Ensure id is present and integer
        $sanitized['id'] = isset($example['id']) && is_numeric($example['id'])
            ? (int)$example['id']
            : ($this->faker ? $this->faker->numberBetween(1, 9999) : 1);

        $currentYear = (int)date('Y');
        $yearStart = strtotime("{$currentYear}-01-01 00:00:00");
        $yearEnd = strtotime("{$currentYear}-12-31 23:59:59");

        foreach ($properties as $fieldName => $fieldSchema) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            // Skip id (already handled)
            if ($fieldName === 'id') {
                continue;
            }

            $value = $example[$fieldName] ?? null;
            $type = $fieldSchema['type'] ?? 'string';

            // Coerce _id fields to integers
            if (str_ends_with($fieldName, '_id') || $fieldName === 'id') {
                if (is_numeric($value)) {
                    $sanitized[$fieldName] = (int)$value;
                } elseif (!isset($sanitized[$fieldName])) {
                    $sanitized[$fieldName] = $this->faker ? $this->faker->numberBetween(1, 9999) : 1;
                }
                continue;
            }

            // Handle by type
            if ($type === 'boolean') {
                $sanitized[$fieldName] = is_bool($value) ? $value : (bool)($value ?? false);
            } elseif ($type === 'integer') {
                $sanitized[$fieldName] = is_numeric($value) ? (int)$value : ($value ?? null);
            } elseif ($type === 'number') {
                $sanitized[$fieldName] = is_numeric($value) ? (float)$value : ($value ?? null);
            } elseif ($type === 'array') {
                $sanitized[$fieldName] = is_array($value) ? $value : ($value ?? []);
            } elseif ($type === 'object') {
                $sanitized[$fieldName] = is_array($value) || is_object($value) ? (array)$value : ($value ?? new \stdClass());
            } else {
                // string
                if (!is_string($value) && $value !== null) {
                    $value = (string)$value;
                }

                // Apply maxLength constraint
                if (isset($fieldSchema['maxLength']) && is_int($fieldSchema['maxLength']) && is_string($value)) {
                    $value = mb_substr($value, 0, $fieldSchema['maxLength']);
                }

                // Ensure email format
                if (($fieldSchema['format'] ?? null) === 'email' && is_string($value)) {
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        $value = $this->faker ? $this->faker->safeEmail : 'user@example.com';
                    }
                }

                // Ensure Ukrainian phone format
                if (str_contains(strtolower($fieldName), 'phone') && is_string($value)) {
                    if (!preg_match('/^\+380\d{9}$/', $value)) {
                        // Generate Ukrainian phone
                        $value = '+380' . ($this->faker ? $this->faker->numerify('#########') : '501234567');
                    }
                }

                // Ensure datetime is in current year
                if (($fieldSchema['format'] ?? null) === 'date-time' && is_string($value)) {
                    try {
                        $timestamp = strtotime($value);
                        if ($timestamp === false || $timestamp < $yearStart || $timestamp > $yearEnd) {
                            // Generate date in current year
                            $randomTimestamp = mt_rand($yearStart, $yearEnd);
                            $value = date(DATE_ATOM, $randomTimestamp);
                        }
                    } catch (\Throwable $e) {
                        $randomTimestamp = mt_rand($yearStart, $yearEnd);
                        $value = date(DATE_ATOM, $randomTimestamp);
                    }
                }

                // Ensure date is in current year
                if (($fieldSchema['format'] ?? null) === 'date' && is_string($value)) {
                    try {
                        $timestamp = strtotime($value);
                        if ($timestamp === false || $timestamp < $yearStart || $timestamp > $yearEnd) {
                            $randomTimestamp = mt_rand($yearStart, $yearEnd);
                            $value = date('Y-m-d', $randomTimestamp);
                        }
                    } catch (\Throwable $e) {
                        $randomTimestamp = mt_rand($yearStart, $yearEnd);
                        $value = date('Y-m-d', $randomTimestamp);
                    }
                }

                $sanitized[$fieldName] = $value;
            }
        }

        // Ensure required fields are present
        foreach ($required as $reqField) {
            if (!isset($sanitized[$reqField]) && $reqField !== 'id') {
                $fieldSchema = $properties[$reqField] ?? null;
                $sanitized[$reqField] = $this->fakeValueForSchema($fieldSchema, $reqField);
            }
        }

        // Add timestamps if not present
        if (!isset($sanitized['created_at'])) {
            $randomTimestamp = mt_rand($yearStart, $yearEnd);
            $sanitized['created_at'] = date(DATE_ATOM, $randomTimestamp);
        }
        if (!isset($sanitized['updated_at'])) {
            $randomTimestamp = mt_rand($yearStart, $yearEnd);
            $sanitized['updated_at'] = date(DATE_ATOM, $randomTimestamp);
        }

        return $sanitized;
    }

    /**
     * Build example using Faker (fallback).
     *
     * @param array<string, mixed> $properties
     * @param array<string, bool> $hiddenSet
     * @return array<string, mixed>
     */
    private function buildFakerExample(array $schema, array $properties, array $hiddenSet): array
    {
        // Try to infer model class from schema context (if available)
        $modelClass = null;
        if (isset($schema['x-model-class']) && is_string($schema['x-model-class'])) {
            $modelClass = $schema['x-model-class'];
        }

        // Extract model constants if available
        $modelConstants = [];
        if ($modelClass !== null) {
            $modelConstants = $this->extractModelConstants($modelClass);
        }

        // Keep examples reasonably small.
        $maxFields = 12;

        // Prefer required fields first.
        $required = $schema['required'] ?? [];
        $orderedFields = [];
        if (is_array($required)) {
            foreach ($required as $r) {
                if (is_string($r) && isset($properties[$r])) {
                    $orderedFields[] = $r;
                }
            }
        }
        foreach (array_keys($properties) as $k) {
            if (!in_array($k, $orderedFields, true)) {
                $orderedFields[] = $k;
            }
        }

        $obj = [
            'id' => $this->faker ? $this->faker->numberBetween(1, 9999) : 1,
        ];

        $count = 0;
        foreach ($orderedFields as $field) {
            if ($count >= $maxFields) {
                break;
            }
            if (!is_string($field) || $field === '') {
                continue;
            }
            if (isset($hiddenSet[$field])) {
                continue;
            }

            $fieldSchema = $properties[$field] ?? null;

            // Check if we have model constants for this field
            $fieldLower = strtolower($field);
            if (!empty($modelConstants)) {
                foreach ($modelConstants as $constField => $values) {
                    if ($fieldLower === $constField || str_contains($fieldLower, $constField) || str_contains($constField, $fieldLower)) {
                        if (is_array($values) && !empty($values)) {
                            // Use first constant value as default
                            $obj[$field] = $values[0];
                            $count++;
                            continue 2;
                        }
                    }
                }
            }

            $obj[$field] = $this->fakeValueForSchema($fieldSchema, $field);
            $count++;
        }

        // Timestamps (common in API responses) - ensure current year
        $currentYear = (int)date('Y');
        $yearStart = strtotime("{$currentYear}-01-01 00:00:00");
        $yearEnd = strtotime("{$currentYear}-12-31 23:59:59");
        $randomTimestamp = mt_rand($yearStart, $yearEnd);
        $obj['created_at'] = date(DATE_ATOM, $randomTimestamp);
        $obj['updated_at'] = date(DATE_ATOM, $randomTimestamp);

        return $obj;
    }

    /**
     * @param mixed $schema
     * @return mixed
     */
    private function fakeValueForSchema($schema, string $fieldName, int $depth = 0)
    {
        if ($depth > 2) {
            return null;
        }

        if (!is_array($schema)) {
            return $this->generateRealisticString($fieldName, null, null);
        }

        if (isset($schema['enum']) && is_array($schema['enum']) && count($schema['enum']) > 0) {
            return $schema['enum'][0];
        }

        if (isset($schema['oneOf']) && is_array($schema['oneOf']) && isset($schema['oneOf'][0])) {
            return $this->fakeValueForSchema($schema['oneOf'][0], $fieldName, $depth + 1);
        }

        $type = $schema['type'] ?? 'string';
        $format = $schema['format'] ?? null;

        if ($type === 'boolean') {
            return $this->faker ? $this->faker->boolean : true;
        }

        if ($type === 'integer') {
            return $this->faker ? $this->faker->numberBetween(1, 9999) : 1;
        }

        if ($type === 'number') {
            return $this->faker ? $this->faker->randomFloat(2, 1, 1000) : 1.23;
        }

        if ($type === 'array') {
            $itemsSchema = $schema['items'] ?? [];
            return [$this->fakeValueForSchema($itemsSchema, $fieldName . '_item', $depth + 1)];
        }

        if ($type === 'object') {
            $props = $schema['properties'] ?? null;
            if (is_array($props) && !empty($props)) {
                $obj = [];
                foreach ($props as $k => $v) {
                    if (is_string($k)) {
                        $obj[$k] = $this->fakeValueForSchema($v, $k, $depth + 1);
                    }
                }
                return $obj;
            }
            return new \stdClass();
        }

        // string
        $minLength = $schema['minLength'] ?? null;
        $maxLength = $schema['maxLength'] ?? null;
        $pattern = $schema['pattern'] ?? null;

        if ($format === 'email') {
            return $this->faker ? $this->faker->safeEmail : 'user@example.com';
        }
        if ($format === 'uuid') {
            return $this->faker ? $this->faker->uuid : '00000000-0000-0000-0000-000000000000';
        }
        if ($format === 'uri') {
            return $this->faker ? $this->faker->url : 'https://example.com';
        }
        if ($format === 'date') {
            $currentYear = (int)date('Y');
            $yearStart = strtotime("{$currentYear}-01-01 00:00:00");
            $yearEnd = strtotime("{$currentYear}-12-31 23:59:59");
            $randomTimestamp = mt_rand($yearStart, $yearEnd);
            return date('Y-m-d', $randomTimestamp);
        }
        if ($format === 'date-time') {
            $currentYear = (int)date('Y');
            $yearStart = strtotime("{$currentYear}-01-01 00:00:00");
            $yearEnd = strtotime("{$currentYear}-12-31 23:59:59");
            $randomTimestamp = mt_rand($yearStart, $yearEnd);
            return date(DATE_ATOM, $randomTimestamp);
        }
        if ($format === 'binary') {
            return '<binary>';
        }

        // Try digits patterns / length constraints
        if (is_string($pattern) && str_contains($pattern, '\\d') && (is_int($minLength) || is_int($maxLength))) {
            $n = is_int($minLength) ? $minLength : (is_int($maxLength) ? $maxLength : 6);
            $n = max(1, min(12, $n));
            return str_repeat('1', $n);
        }

        // Generate realistic string based on field name
        return $this->generateRealisticString($fieldName, $minLength, $maxLength);
    }

    /**
     * Generate realistic string value based on field name patterns.
     */
    private function generateRealisticString(string $fieldName, ?int $minLength, ?int $maxLength): string
    {
        $fieldLower = strtolower($fieldName);

        // Phone numbers - Ukrainian format
        if (str_contains($fieldLower, 'phone')) {
            return '+380' . ($this->faker ? $this->faker->numerify('#########') : '501234567');
        }

        // State/Status fields - use common values
        if ($fieldLower === 'state') {
            return 'active'; // Default to active
        }
        if ($fieldLower === 'status') {
            return 'pending'; // Default to pending
        }

        // Names
        if (in_array($fieldLower, ['name', 'first_name', 'last_name', 'full_name', 'user_name'])) {
            $name = $this->faker ? $this->faker->name : 'John Doe';
            return $this->applyLengthConstraint($name, $minLength, $maxLength);
        }

        // Emails
        if (str_contains($fieldLower, 'email')) {
            return $this->faker ? $this->faker->safeEmail : 'user@example.com';
        }

        // Titles
        if (in_array($fieldLower, ['title', 'course_title', 'lesson_title'])) {
            $title = $this->faker ? $this->faker->sentence(3, false) : 'Example Course Title';
            return $this->applyLengthConstraint($title, $minLength, $maxLength);
        }

        // Descriptions
        if (str_contains($fieldLower, 'description') || str_contains($fieldLower, 'bio') || str_contains($fieldLower, 'about')) {
            $desc = $this->faker ? $this->faker->paragraph(2) : 'This is an example description.';
            return $this->applyLengthConstraint($desc, $minLength, $maxLength);
        }

        // URLs
        if (str_contains($fieldLower, 'url') || str_contains($fieldLower, 'link') || str_contains($fieldLower, 'website')) {
            return $this->faker ? $this->faker->url : 'https://example.com';
        }

        // Slugs
        if (str_contains($fieldLower, 'slug')) {
            $slug = $this->faker ? Str::slug($this->faker->words(3, true)) : 'example-slug';
            return $this->applyLengthConstraint($slug, $minLength, $maxLength);
        }

        // Codes
        if (str_contains($fieldLower, 'code')) {
            $code = $this->faker ? strtoupper($this->faker->bothify('??####')) : 'ABC123';
            return $this->applyLengthConstraint($code, $minLength, $maxLength);
        }

        // Address/Location
        if (in_array($fieldLower, ['address', 'location', 'city', 'country'])) {
            $location = $this->faker ? $this->faker->city : 'Kyiv';
            return $this->applyLengthConstraint($location, $minLength, $maxLength);
        }

        // Default: use word or sentence
        $len = $maxLength ?? 20;
        if (is_int($minLength) && $minLength > 0) {
            $len = max($len, $minLength);
        }
        if (is_int($maxLength) && $maxLength > 0) {
            $len = min($len, $maxLength);
        }

        if ($this->faker) {
            if ($len > 50) {
                $val = $this->faker->sentence(4, false);
            } else {
                $val = $this->faker->words(2, true);
            }
            return $this->applyLengthConstraint($val, $minLength, $maxLength);
        }

        return 'example';
    }

    /**
     * Apply min/max length constraints to a string.
     */
    private function applyLengthConstraint(string $value, ?int $minLength, ?int $maxLength): string
    {
        if (is_int($maxLength) && $maxLength > 0 && mb_strlen($value) > $maxLength) {
            $value = mb_substr($value, 0, $maxLength);
        }

        if (is_int($minLength) && $minLength > 0 && mb_strlen($value) < $minLength) {
            $value = str_pad($value, $minLength, 'x');
        }

        return $value;
    }

    /**
     * @return array<int, string>
     */
    private function extractSendResponseArrayKeysFromAction(string $action): array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return [];
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return [];
        }

        if (!preg_match('/sendResponse\\s*\\(\\s*\\[/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return [];
        }

        $matchPos = $m[0][1] ?? null;
        if (!is_int($matchPos)) {
            return [];
        }

        $arrayStart = strpos($source, '[', $matchPos);
        if ($arrayStart === false) {
            return [];
        }

        $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
        if (!is_string($arrayLiteral) || $arrayLiteral === '') {
            return [];
        }

        $keys = [];
        preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $mm);
        if (isset($mm['key']) && is_array($mm['key'])) {
            foreach ($mm['key'] as $k) {
                if (is_string($k) && $k !== '') {
                    $keys[$k] = true;
                }
            }
        }

        $result = array_keys($keys);
        sort($result);
        return $result;
    }

    /**
     * Extract nested response structure from sendResponse([...]) including arrays of objects.
     *
     * @return array<string, mixed>|null
     */
    private function extractSendResponseStructureFromAction(string $action): ?array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return null;
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return null;
        }

        // Find sendResponse([...])
        if (!preg_match('/sendResponse\\s*\\(\\s*\\[/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matchPos = $m[0][1] ?? null;
        if (!is_int($matchPos)) {
            return null;
        }

        $arrayStart = strpos($source, '[', $matchPos);
        if ($arrayStart === false) {
            return null;
        }

        $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
        if (!is_string($arrayLiteral) || $arrayLiteral === '') {
            return null;
        }

        $structure = [];

        // Parse array literal to detect:
        // 1. Simple key => value pairs
        // 2. Key => array_values($var) patterns (arrays)
        // 3. Key => $var patterns (objects or null)

        // Match: 'key' => value or "key" => value
        // Also match: 'key' => array_values($var) or 'key' => $var
        preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>\\s*(?<value>[^,]+)/m", $arrayLiteral, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match['key'] ?? null;
            $value = trim($match['value'] ?? '');

            if (!is_string($key) || $key === '') {
                continue;
            }

            // Check if value is array_values($var) - indicates array of objects
            if (preg_match('/array_values\\s*\\(\\s*\\$(?<var>\w+)\\s*\\)/', $value, $varMatch)) {
                $varName = $varMatch['var'] ?? null;
                if ($varName) {
                    // Try to find where this variable is built
                    $arrayStructure = $this->extractArrayStructureFromVariable($source, $varName);
                    if ($arrayStructure !== null) {
                        $structure[$key] = [
                            'type' => 'array',
                            'items' => $arrayStructure,
                        ];
                    } else {
                        // Fallback: assume array of objects with common fields
                        $structure[$key] = [
                            'type' => 'array',
                            'items' => $this->inferArrayItemStructure($key),
                        ];
                    }
                } else {
                    $structure[$key] = ['type' => 'array', 'items' => ['type' => 'object']];
                }
            }
            // Check if value is just $var (could be array, object, or null)
            elseif (preg_match('/^\\$(?<var>\w+)$/', $value, $varMatch)) {
                $varName = $varMatch['var'] ?? null;
                if ($varName) {
                    // Try to find where this variable is built - check for arrays first
                    $arrayStructure = $this->extractArrayStructureFromCollectionVariable($source, $varName);
                    if ($arrayStructure !== null) {
                        $structure[$key] = [
                            'type' => 'array',
                            'items' => $arrayStructure,
                        ];
                    } else {
                        // Try object structure
                        $objectStructure = $this->extractObjectStructureFromVariable($source, $varName);
                        if ($objectStructure !== null) {
                            $structure[$key] = [
                                'type' => 'object',
                                'nullable' => true,
                                'properties' => $objectStructure,
                            ];
                        } else {
                            // Fallback: infer from key name
                            $structure[$key] = ['type' => 'object', 'nullable' => true];
                        }
                    }
                }
            }
            // Simple value (string, number, etc.)
            else {
                // We'll handle this in the simple keys extraction
            }
        }

        return !empty($structure) ? $structure : null;
    }

    /**
     * Extract structure of array elements from variable assignment.
     *
     * @return array<string, mixed>|null
     */
    private function extractArrayStructureFromVariable(string $source, string $varName): ?array
    {
        // Look for patterns like: $var[$key] = ['id' => ..., 'name' => ...]
        // Handle multi-line array assignments
        $pattern = '/\\$' . preg_quote($varName, '/') . '\\s*\\[.*?\\]\\s*=\\s*\\[/s';

        if (preg_match($pattern, $source, $match, PREG_OFFSET_CAPTURE)) {
            $matchPos = $match[0][1] ?? null;
            if (!is_int($matchPos)) {
                return null;
            }

            // Find the opening bracket
            $arrayStart = strpos($source, '[', $matchPos);
            if ($arrayStart === false) {
                return null;
            }

            // Extract the full array literal (handles nested brackets)
            $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
            if (!is_string($arrayLiteral) || $arrayLiteral === '') {
                return null;
            }

            // Extract keys from the array literal
            $keys = [];
            preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $keyMatches);
            if (isset($keyMatches['key']) && is_array($keyMatches['key'])) {
                foreach ($keyMatches['key'] as $k) {
                    if (is_string($k) && $k !== '') {
                        $keys[$k] = true;
                    }
                }
            }

            if (!empty($keys)) {
                $properties = [];
                foreach (array_keys($keys) as $key) {
                    $properties[$key] = $this->inferFieldType($key);
                }
                return ['type' => 'object', 'properties' => $properties];
            }
        }

        return null;
    }

    /**
     * Extract structure of array elements from collection variable (e.g., $events->push([...]), ->map(function() { return [...] })).
     *
     * @return array<string, mixed>|null
     */
    private function extractArrayStructureFromCollectionVariable(string $source, string $varName): ?array
    {
        $allKeys = [];

        // Pattern 1: $var->push([...]) or $var->add([...])
        // Also handle: $events->push([...]) where $events = collect()
        $pushPattern = '/\\$' . preg_quote($varName, '/') . '\\s*->\\s*(?:push|add)\\s*\\(\\s*\\[/s';
        if (preg_match_all($pushPattern, $source, $pushMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($pushMatches[0] as $match) {
                $matchPos = $match[1] ?? null;
                if (!is_int($matchPos)) {
                    continue;
                }

                // Find the opening bracket after push/add
                $arrayStart = strpos($source, '[', $matchPos);
                if ($arrayStart === false) {
                    continue;
                }

                // Extract the full array literal (handles nested brackets)
                $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
                if (is_string($arrayLiteral) && $arrayLiteral !== '') {
                    // Extract keys from array literal
                    preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $keyMatches);
                    if (isset($keyMatches['key']) && is_array($keyMatches['key'])) {
                        foreach ($keyMatches['key'] as $k) {
                            if (is_string($k) && $k !== '') {
                                $allKeys[$k] = true;
                            }
                        }
                    }
                }
            }
        }

        // Pattern 2: ->map(function ($item) { return [...] })
        $mapPattern = '/->\\s*map\\s*\\(\\s*function\\s*\\([^)]*\\)\\s*\\{[^}]*return\\s*\\[/s';
        if (preg_match_all($mapPattern, $source, $mapMatches, PREG_OFFSET_CAPTURE)) {
            foreach ($mapMatches[0] as $match) {
                $matchPos = $match[1] ?? null;
                if (!is_int($matchPos)) {
                    continue;
                }

                // Find the opening bracket after return
                $returnPos = strpos($source, 'return', $matchPos);
                if ($returnPos === false) {
                    continue;
                }

                $arrayStart = strpos($source, '[', $returnPos);
                if ($arrayStart === false) {
                    continue;
                }

                // Extract the full array literal
                $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
                if (is_string($arrayLiteral) && $arrayLiteral !== '') {
                    preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $keyMatches);
                    if (isset($keyMatches['key']) && is_array($keyMatches['key'])) {
                        foreach ($keyMatches['key'] as $k) {
                            if (is_string($k) && $k !== '') {
                                $allKeys[$k] = true;
                            }
                        }
                    }
                }
            }
        }

        // Pattern 3: Check if variable is assigned from a collection operation that ends with ->map(...)
        // e.g., $tokens = $user->tokens()->get()->map(function($token) { return [...] })
        $assignmentPattern = '/\\$' . preg_quote($varName, '/') . '\\s*=\\s*[^;]*->\\s*map\\s*\\(/s';
        if (preg_match($assignmentPattern, $source, $assignMatch)) {
            // Extract the map part
            $mapStart = strpos($source, '->map', strpos($source, '$' . $varName));
            if ($mapStart !== false) {
                // Find return statement in the map callback
                $mapEnd = strpos($source, '});', $mapStart);
                if ($mapEnd !== false) {
                    $mapBlock = substr($source, $mapStart, $mapEnd - $mapStart);
                    if (preg_match('/return\\s*\\[/s', $mapBlock, $returnMatch, PREG_OFFSET_CAPTURE)) {
                        $returnPos = $returnMatch[0][1] ?? null;
                        if (is_int($returnPos)) {
                            $arrayStart = strpos($mapBlock, '[', $returnPos);
                            if ($arrayStart !== false) {
                                $arrayLiteral = $this->extractBracketedSubstring($mapBlock, (int)$arrayStart, '[', ']');
                                if (is_string($arrayLiteral) && $arrayLiteral !== '') {
                                    preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $keyMatches);
                                    if (isset($keyMatches['key']) && is_array($keyMatches['key'])) {
                                        foreach ($keyMatches['key'] as $k) {
                                            if (is_string($k) && $k !== '') {
                                                $allKeys[$k] = true;
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        if (!empty($allKeys)) {
            $properties = [];
            foreach (array_keys($allKeys) as $key) {
                $properties[$key] = $this->inferFieldType($key);
            }
            return ['type' => 'object', 'properties' => $properties];
        }

        return null;
    }

    /**
     * Extract structure from variables used in sendResponse (e.g., 'items' => $sortedEvents).
     *
     * @return array<string, mixed>|null
     */
    private function extractStructureFromVariables(string $action): ?array
    {
        [$class, $method] = $this->parseControllerAction($action);
        if ($class === null || $method === null) {
            return null;
        }

        $source = $this->getMethodSource($class, $method);
        if (!is_string($source) || $source === '') {
            return null;
        }

        // Find sendResponse([...])
        if (!preg_match('/sendResponse\\s*\\(\\s*\\[/', $source, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $matchPos = $m[0][1] ?? null;
        if (!is_int($matchPos)) {
            return null;
        }

        $arrayStart = strpos($source, '[', $matchPos);
        if ($arrayStart === false) {
            return null;
        }

        $arrayLiteral = $this->extractBracketedSubstring($source, (int)$arrayStart, '[', ']');
        if (!is_string($arrayLiteral) || $arrayLiteral === '') {
            return null;
        }

        $structure = [];

        // Match: 'key' => $var
        preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>\\s*\\$(?<var>\w+)/m", $arrayLiteral, $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $key = $match['key'] ?? null;
            $varName = $match['var'] ?? null;

            if (!is_string($key) || $key === '' || !is_string($varName) || $varName === '') {
                continue;
            }

            // Try to extract array structure from collection variable
            // First try the exact variable name
            $arrayStructure = $this->extractArrayStructureFromCollectionVariable($source, $varName);
            
            // If not found, try to trace variable assignments (e.g., $sortedEvents = $events->sortByDesc(...))
            if ($arrayStructure === null) {
                $arrayStructure = $this->traceVariableToSource($source, $varName);
            }
            
            // Always create structure entry, even if extraction failed
            if ($arrayStructure !== null) {
                $structure[$key] = [
                    'type' => 'array',
                    'items' => $arrayStructure,
                ];
            } else {
                // Fallback: infer from key name - always create structure
                $inferredItem = $this->inferArrayItemStructure($key);
                $structure[$key] = [
                    'type' => 'array',
                    'items' => $inferredItem,
                ];
            }
        }

        return !empty($structure) ? $structure : null;
    }

    /**
     * Trace a variable back to its source (e.g., $sortedEvents = $events->sortByDesc(...) -> trace $events)
     *
     * @return array<string, mixed>|null
     */
    private function traceVariableToSource(string $source, string $varName): ?array
    {
        // Look for assignments like: $var = $otherVar->method(...)
        // Handle chained methods: $sortedEvents = $events->sortByDesc(...)->take(...)->values()
        $assignmentPattern = '/\\$' . preg_quote($varName, '/') . '\\s*=\\s*\\$(?<source>\w+)\\s*->/';
        if (preg_match($assignmentPattern, $source, $match)) {
            $sourceVar = $match['source'] ?? null;
            if ($sourceVar) {
                // Try to extract structure from the source variable
                $structure = $this->extractArrayStructureFromCollectionVariable($source, $sourceVar);
                if ($structure !== null) {
                    return $structure;
                }
            }
        }
        
        // Also try direct assignment: $var = $otherVar
        $directPattern = '/\\$' . preg_quote($varName, '/') . '\\s*=\\s*\\$(?<source>\w+)\\s*;/';
        if (preg_match($directPattern, $source, $match)) {
            $sourceVar = $match['source'] ?? null;
            if ($sourceVar) {
                return $this->extractArrayStructureFromCollectionVariable($source, $sourceVar);
            }
        }
        
        return null;
    }

    /**
     * Extract structure of object from variable assignment.
     *
     * @return array<string, mixed>|null
     */
    private function extractObjectStructureFromVariable(string $source, string $varName): ?array
    {
        // Look for patterns like: $var = ['id' => ..., 'name' => ...]
        // or: $var = array('id' => ..., 'name' => ...)
        $pattern = '/\\$' . preg_quote($varName, '/') . '\\s*=\\s*\\[([^\\]]+)\\]/s';

        if (preg_match($pattern, $source, $match)) {
            $arrayLiteral = $match[1] ?? '';
            $keys = [];
            preg_match_all("/['\\\"](?<key>[^'\\\"]+)['\\\"]\\s*=>/m", $arrayLiteral, $keyMatches);
            if (isset($keyMatches['key']) && is_array($keyMatches['key'])) {
                foreach ($keyMatches['key'] as $k) {
                    if (is_string($k) && $k !== '') {
                        $keys[$k] = true;
                    }
                }
            }

            if (!empty($keys)) {
                $properties = [];
                foreach (array_keys($keys) as $key) {
                    $properties[$key] = $this->inferFieldType($key);
                }
                return $properties;
            }
        }

        return null;
    }

    /**
     * Infer structure of array items based on key name.
     *
     * @return array<string, mixed>
     */
    private function inferArrayItemStructure(string $arrayKey): array
    {
        $keyLower = strtolower($arrayKey);

        // Common patterns for arrays of objects
        if (str_contains($keyLower, 'instructor') || str_contains($keyLower, 'classmate') || str_contains($keyLower, 'user')) {
            return [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'avatar_url' => ['type' => 'string', 'format' => 'uri', 'nullable' => true],
                    'course_title' => ['type' => 'string', 'nullable' => true],
                    'course_id' => ['type' => 'integer', 'nullable' => true],
                ],
            ];
        }

        // Activity/event items - check if key is "items" and method/action suggests activity
        if ($keyLower === 'items') {
            // Check if this is likely an activity endpoint by checking the action context
            // For now, assume items could be activity items
            return [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'data' => ['type' => 'object'],
                ],
            ];
        }
        
        // Activity/event items (explicit check)
        if (str_contains($keyLower, 'activity') || str_contains($keyLower, 'event')) {
            return [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'string'],
                    'title' => ['type' => 'string'],
                    'description' => ['type' => 'string'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'data' => ['type' => 'object'],
                ],
            ];
        }

        // Session items
        if (str_contains($keyLower, 'session')) {
            return [
                'type' => 'object',
                'properties' => [
                    'id' => ['type' => 'integer'],
                    'name' => ['type' => 'string'],
                    'device_name' => ['type' => 'string'],
                    'device_type' => ['type' => 'string'],
                    'browser' => ['type' => 'string'],
                    'platform' => ['type' => 'string'],
                    'ip_address' => ['type' => 'string'],
                    'last_used_at' => ['type' => 'string', 'format' => 'date-time'],
                    'created_at' => ['type' => 'string', 'format' => 'date-time'],
                    'is_current' => ['type' => 'boolean'],
                ],
            ];
        }

        // Default object structure
        return ['type' => 'object'];
    }

    /**
     * Infer field type from field name.
     *
     * @return array<string, mixed>
     */
    private function inferFieldType(string $fieldName): array
    {
        $fieldLower = strtolower($fieldName);

        if (str_ends_with($fieldLower, '_id') || $fieldLower === 'id') {
            return ['type' => 'integer'];
        }
        if (str_contains($fieldLower, 'email')) {
            return ['type' => 'string', 'format' => 'email'];
        }
        if (str_contains($fieldLower, 'url') || str_contains($fieldLower, 'avatar')) {
            return ['type' => 'string', 'format' => 'uri', 'nullable' => true];
        }
        if (str_contains($fieldLower, 'title') || str_contains($fieldLower, 'name') || str_contains($fieldLower, 'description')) {
            return ['type' => 'string'];
        }
        if (str_contains($fieldLower, 'course_title')) {
            return ['type' => 'string', 'nullable' => true];
        }
        if (str_contains($fieldLower, 'course_id')) {
            return ['type' => 'integer', 'nullable' => true];
        }
        // Boolean fields
        if (str_starts_with($fieldLower, 'is_') || str_contains($fieldLower, '_enabled') || str_contains($fieldLower, '_current')) {
            return ['type' => 'boolean'];
        }
        // Date/time fields
        if (str_contains($fieldLower, '_at') || str_contains($fieldLower, '_date') || str_contains($fieldLower, 'created_at') || str_contains($fieldLower, 'updated_at') || str_contains($fieldLower, 'last_used')) {
            return ['type' => 'string', 'format' => 'date-time'];
        }
        // IP address
        if (str_contains($fieldLower, 'ip_address') || str_contains($fieldLower, 'ip')) {
            return ['type' => 'string'];
        }
        // Device/browser/platform fields
        if (str_contains($fieldLower, 'device_') || str_contains($fieldLower, 'browser') || str_contains($fieldLower, 'platform')) {
            return ['type' => 'string'];
        }
        // Data field (usually object)
        if ($fieldLower === 'data') {
            return ['type' => 'object'];
        }

        return ['type' => 'string'];
    }

    /**
     * Build example from extracted structure.
     *
     * @param array<string, mixed> $structure
     * @return array<string, mixed>
     */
    private function buildExampleFromStructure(array $structure): array
    {
        $example = [];

        foreach ($structure as $key => $spec) {
            if (!is_array($spec)) {
                continue;
            }

            $type = $spec['type'] ?? 'string';

            if ($type === 'array') {
                $items = $spec['items'] ?? ['type' => 'object'];
                $inferredItem = $this->inferArrayItemStructure($key);
                $itemExample = null;
                
                // Try to build from items spec first
                if (is_array($items) && !empty($items['properties'] ?? [])) {
                    $itemExample = $this->buildItemExample($items);
                }
                
                // If that failed, use inferred structure
                if (empty($itemExample) || (!is_array($itemExample) && !is_object($itemExample))) {
                    if (!empty($inferredItem['properties'] ?? [])) {
                        $itemExample = $this->buildItemExample($inferredItem);
                    }
                }
                
                // Convert to array and ensure it has content
                if ($itemExample instanceof \stdClass) {
                    $itemExample = (array)$itemExample;
                }
                
                // Convert nested objects to arrays recursively
                if (is_array($itemExample)) {
                    foreach ($itemExample as $k => $v) {
                        if ($v instanceof \stdClass) {
                            $itemExample[$k] = (array)$v;
                        }
                    }
                }
                
                // Check if itemExample is empty or invalid
                $hasValidItem = !empty($itemExample) && is_array($itemExample) && count($itemExample) > 0;
                
                if ($hasValidItem) {
                    $example[$key] = [$itemExample];
                } elseif (!empty($inferredItem['properties'] ?? [])) {
                    // Build directly from inferred properties as fallback
                    $directExample = [];
                    foreach ($inferredItem['properties'] as $propKey => $propSpec) {
                        $value = $this->fakeValueForSchema($propSpec, $propKey);
                        // Ensure types are correct
                        if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'boolean') {
                            $value = is_bool($value) ? $value : (bool)$value;
                        }
                        if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'integer') {
                            $value = is_numeric($value) ? (int)$value : $value;
                        }
                        // Convert nested objects to arrays
                        if ($value instanceof \stdClass) {
                            $value = (array)$value;
                        }
                        $directExample[$propKey] = $value;
                    }
                    // Always add at least one item if we have properties
                    if (!empty($directExample)) {
                        $example[$key] = [$directExample];
                    } else {
                        $example[$key] = [];
                    }
                } else {
                    $example[$key] = [];
                }
            } elseif ($type === 'object') {
                $properties = $spec['properties'] ?? [];
                $example[$key] = $this->buildItemExample(['type' => 'object', 'properties' => $properties]);
            } else {
                $example[$key] = $this->fakeValueForSchema($spec, $key);
            }
        }

        return $example;
    }

    /**
     * Build example for a single item (object or primitive).
     *
     * @param array<string, mixed> $itemSpec
     * @return mixed
     */
    private function buildItemExample(array $itemSpec)
    {
        $type = $itemSpec['type'] ?? 'object';

        if ($type === 'object') {
            $properties = $itemSpec['properties'] ?? [];
            $item = [];
            
            // Always build properties if available
            if (!empty($properties)) {
                foreach ($properties as $propKey => $propSpec) {
                    $value = $this->fakeValueForSchema($propSpec, $propKey);
                    // Ensure boolean fields are actually boolean
                    if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'boolean') {
                        $value = is_bool($value) ? $value : (bool)$value;
                    }
                    // Ensure integer fields are actually integers
                    if (is_array($propSpec) && ($propSpec['type'] ?? null) === 'integer') {
                        $value = is_numeric($value) ? (int)$value : $value;
                    }
                    $item[$propKey] = $value;
                }
            }
            
            // Return array (not stdClass) to ensure it can be used in arrays
            return $item;
        }

        return $this->fakeValueForSchema($itemSpec, 'item');
    }

    /**
     * @param array<int, string> $keys
     * @return array<string, mixed>
     */
    private function fakeObjectFromKeys(array $keys): array
    {
        $obj = [];

        foreach ($keys as $k) {
            $lower = strtolower($k);
            if (str_contains($lower, 'count')) {
                $obj[$k] = $this->faker ? $this->faker->numberBetween(0, 10) : 1;
            } elseif (str_contains($lower, 'url')) {
                $obj[$k] = $this->faker ? $this->faker->url : 'https://example.com';
            } elseif (str_contains($lower, 'token')) {
                $obj[$k] = $this->fakeToken();
            } elseif (str_contains($lower, 'secret')) {
                $obj[$k] = $this->faker ? $this->faker->regexify('[A-Z2-7]{16}') : 'EXAMPLESECRET000000';
            } elseif (str_contains($lower, 'requires') || str_starts_with($lower, 'is_')) {
                $obj[$k] = $this->faker ? $this->faker->boolean : true;
            } elseif (str_ends_with($lower, 'id') || str_contains($lower, '_id')) {
                $obj[$k] = $this->faker ? $this->faker->numberBetween(1, 9999) : 1;
            } else {
                $obj[$k] = $this->faker ? $this->faker->word : 'example';
            }
        }

        return $obj;
    }
}



