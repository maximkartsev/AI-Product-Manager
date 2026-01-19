<?php

namespace App\Models;

use App\Builders\BaseQueryBuilder;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator as Validator;
use Illuminate\Support\Str;
use Spatie\Activitylog\LogOptions;
use Spatie\Activitylog\Models\Activity;
use Spatie\Activitylog\Traits\LogsActivity;


/**
 * App\Models\BaseModel
 *
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel newModelQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel newQuery()
 * @method static \Illuminate\Database\Eloquent\Builder|BaseModel query()
 * @mixin \Eloquent
 *
 * @method static find($id)
 */
class BaseModel extends Model
{

    use LogsActivity;

    public $needToSign = false;
    public $signingUserId = null;
    public $signingUserBarcode = null;

    /**
     * Create a new Eloquent model instance.
     *
     * @param array $attributes
     * @return void
     */
    public function __construct(array $attributes = [], $fast = false)
    {
        if ($fast) {
            $this->fillFast($attributes);
        } else {
            $this->bootIfNotBooted();

            $this->initializeTraits();

            $this->syncOriginal();

            $this->fill($attributes);
        }
    }

    public function getName()
    {

        if($this->name) return $this->name;

        $humanReadableName = ucwords(preg_replace('/(?!^)([A-Z])/', ' $0', class_basename($this)));

        return $humanReadableName." ".$this->id;

    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fillFast(array $attributes)
    {
        foreach ($attributes as $key => $value) {
            $this->attributes[$key] = $value;
        }


        return $this;
    }

    /**
     * Fill the model with an array of attributes.
     *
     * @param array $attributes
     * @return $this
     *
     * @throws \Illuminate\Database\Eloquent\MassAssignmentException
     */
    public function fill(array $attributes)
    {
        parent::fill($attributes);

        return $this;
    }

    /**
     * @return array
     */
    static public function getFillableFieldNames(): array
    {

        return (new static([], true))->getFillable();
    }

    static public function hasTimeStamps(): bool
    {
        return (new static([], true))->timestamps;
    }

    public function getCreatedAtAttribute() {
        if(!isset($this->attributes['created_at'])) return null;
        return Carbon::parse($this->attributes['created_at'])->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute() {
        if(!isset($this->attributes['updated_at'])) return null;
        return Carbon::parse($this->attributes['updated_at'])->format('Y-m-d H:i:s');
    }

    static public function filterFieldsToSelect($fields = [])
    {
        if ($fields === ["*"]) {
            return $fields;
        }

        if (is_string($fields)) {
            $fields = [$fields];
        }

        $modelFields = static::getFillableFieldNames();
        $modelFields[] = 'id';
        $modelFields[] = 'created_at';
        $modelFields[] = 'updated_at';

        return array_intersect($modelFields, $fields);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['*']);
    }

    public function getRelationNames($skipRelationKinds = [])
    {
        $names = [];
        $reflection = new \ReflectionClass(static::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $params = $method->getParameters();
            if ($method->getReturnType() !== null && $method->getReturnType() instanceof \ReflectionNamedType) {
                if (count($params) === 0 && is_subclass_of(
                        $method->getReturnType()->getName(),
                        'Illuminate\Database\Eloquent\Relations\Relation'
                    )) {

                    if (!empty($skipRelationKinds) && in_array($method->getReturnType()->getName(), $skipRelationKinds)) {
                        continue;
                    }

                    $names[] = $method->getName();
                }
            }
        }


        return $names;
    }

    public function getHasManyRelationObjectIds($allowedMethods = [])
    {
        $hasManyRelations = [];

        $names = [];
        $reflection = new \ReflectionClass(static::class);
        $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);

        foreach ($methods as $method) {
            $params = $method->getParameters();
            if ($method->getReturnType() !== null) {
                $typeObject = $method->getReturnType();

                if($typeObject instanceof \ReflectionNamedType) {
                    $type = $typeObject->getName();
                } else {
                    continue;
                }

                if (count($params) === 0 && $type == 'Illuminate\Database\Eloquent\Relations\HasMany') {
                    $name = $method->getName();

                    if (!empty($allowedMethods) && !in_array($name, $allowedMethods)) {
                        continue;
                    }

                    $names[] = $name;

                    $model = $this->{$name}()->getModel();
                    $class = get_class($model);
                    $ids = $this->{$name}()->pluck('id')->toArray();

                    if (!empty($ids)) {
                        $hasManyRelations[$class] = $ids;
                    }
                }
            }
        }

        return $hasManyRelations;
    }

    protected function castAttribute($key, $value)
    {
        if (array_key_exists($key, $this->casts) && !is_null($value)) {
            switch ($this->getCastType($key)) {
                case 'float':
                case 'decimal':
                    return (float)$value;
                case 'int':
                    return (int)round($value);
            }
        }

        return parent::castAttribute($key, $value);
    }

    static function getRelatedModelClass(string $className, string $methodName): ?string
    {
        if (!class_exists($className)) {
            throw new \Exception("Class $className does not exist");
        }

        if (!method_exists($className, $methodName)) {
            throw new \Exception("Method $methodName does not exist in class $className");
        }

        try {
            $reflectionMethod = new \ReflectionMethod($className, $methodName);

            if ($reflectionMethod->getNumberOfParameters() === 0) {
                $returnType = $reflectionMethod->invoke(new $className);

                if ($returnType instanceof Relation) {
                    return get_class($returnType->getRelated());
                } else {
                    throw new \Exception("Method $methodName in class $className does not return a Relation");
                }
            } else {
                throw new \Exception("Method $methodName in class $className has parameters");
            }
        } catch (\ReflectionException $e) {
            throw new \Exception("ReflectionException: " . $e->getMessage());
        }
    }

    public static function addNestedRules($rules)
    {
        $className = static::class;

        $relations = [];

        $classMethods = get_class_methods($className);

        foreach ($classMethods as $method) {
            try {
                $reflectionMethod = new \ReflectionMethod($className, $method);

                if ($reflectionMethod->getNumberOfParameters() === 0) {
                    $returnType = $reflectionMethod->getReturnType();

                    if (!$returnType) {
                        continue;
                    }

                    if (is_subclass_of($returnType->getName(), 'Illuminate\Database\Eloquent\Relations\Relation')) {
                        $relatedModelClass = static::getRelatedModelClass(get_class(new static()), $method);

                        $relations[$method] = [
                            'type' => $returnType->getName(),
                            'related_model' => $relatedModelClass,
                        ];

                        $relationSeparator = (!in_array($returnType, [BelongsToMany::class, HasMany::class]
                        )) ? '.' : '.*.';

                        /** @var BaseModel $relatedModelClass */

                        if (!method_exists($relatedModelClass, 'getRules')) {
                            continue;
                        }

                        $relationRules = $relatedModelClass::getRules();

                        foreach ($relationRules as $fieldName => $rule) {
                            $rules[$method . $relationSeparator . $fieldName] = "sometimes|" . $rule;
                        }
                    }
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }

        return $rules;
    }

    public static function getEloquentRelationships(string $className): array
    {
        if (!class_exists($className)) {
            throw new \Exception("Class $className does not exist");
        }

        $classMethods = get_class_methods($className);
        $relationships = [];

        foreach ($classMethods as $method) {
            try {
                $reflectionMethod = new \ReflectionMethod($className, $method);
                if ($reflectionMethod->getNumberOfParameters() === 0) {
                    $returnType = $reflectionMethod->getReturnType();

                    if (!$returnType) {
                        continue;
                    }

                    if (is_subclass_of($returnType->getName(), 'Illuminate\Database\Eloquent\Relations\Relation')) {
                        $relatedModelClass = static::getRelatedModelClass(get_class(new static()), $method);

                        $relationships[$method] = [
                            'type' => $returnType->getName(),
                            'related_model' => $relatedModelClass,
                        ];
                    }
                }
            } catch (\ReflectionException $e) {
                continue;
            }
        }

        return $relationships;
    }

    static public function firstOrCreateOrError($input): array
    {
        $entityInput = [];
        $entityFields = static::getFillableFieldNames();

        foreach ($entityFields as $fieldName) {
            if (isset($input[$fieldName])) {
                $entityInput[$fieldName] = $input[$fieldName];
            }
        }

        $validator = Validator::make($entityInput, static::getRules());

        $res = [
            'entity' => null,
            'errors' => null,
        ];

        if ($validator->fails()) {
            if (!empty($entityInput)) {
                $entityQuery = static::select(['*']);

                foreach ($entityInput as $key => $value) {
                    $entityQuery->where($key, $value);
                }

                $entity = $entityQuery->first();
            } else {
                $entity = null;
            }

            if (!($entity instanceof static)) {
                $res['errors'] = $validator->errors();

                return $res;
            }
        } else {
            $entity = static::create($entityInput);
        }

        $res['entity'] = $entity;

        return $res;
    }


    public static function createWithRelations(array $attributes): self
    {
        return \DB::transaction(function () use ($attributes) {
            $model = new static;

            $relationships = array_keys(static::getEloquentRelationships(static::class));

            foreach ($relationships as $relationship) {
                if (isset($attributes[$relationship])) {
                    $relationInstance = $model->$relationship();

                    if ($relationInstance instanceof BelongsTo) {
                        $parent = $relationInstance->getRelated()->create($attributes[$relationship]);
                        $model->setAttribute($relationInstance->getForeignKeyName(), $parent->getKey());
                        unset($attributes[$relationship]);  // remove this relationship data from the attributes
                    }
                }
            }

            // Fill the attributes into the model and save it
            $model->fill($attributes)->save();

            // handle hasMany relations after parent model was saved
            foreach ($relationships as $relationship) {
                if (isset($attributes[$relationship])) {
                    $relationInstance = $model->$relationship();

                    if ($relationInstance instanceof HasMany) {
                        foreach ($attributes[$relationship] as $relationAttributes) {
                            $relationInstance->create($relationAttributes);
                        }
                        unset($attributes[$relationship]);  // remove this relationship data from the attributes
                    }
                }
            }

            return $model;
        });
    }

    public static function array_diff_recursive($array1, $array2)
    {
        $result = [];

        foreach ($array1 as $key => $value) {
            if (is_array($value)) {
                if (!isset($array2[$key]) || !is_array($array2[$key])) {
                    $result[$key] = $value;
                } else {
                    $diff = BaseModel::array_diff_recursive($value, $array2[$key]);
                    if (!empty($diff)) {
                        $result[$key] = $diff;
                    }
                }
            } elseif (!array_key_exists($key, $array2) || $array2[$key] !== $value) {
                $result[$key] = $value;
            }
        }

        return $result;
    }

    public function newEloquentBuilder($query)
    {
        return new BaseQueryBuilder($query);
    }

    public function updateAttributesFromTranslation($translation)
    {
        if ($translation) {

            $attributesToSet = [];

            // List of translation fields that should be accessible on the model even if not in base table
            $translationOnlyFields = ['banner_title', 'banner_subtitle', 'banner_slogans'];

            // Fields that are stored as JSON and should be decoded
            $jsonFields = ['banner_slogans'];

            // Get translation attributes - use getAttributes() to ensure we get all attributes including casts
            $translationAttributes = $translation->getAttributes();

            foreach ($translationAttributes as $key => $value) {
                if($key == 'id') continue;
                // Set field if it exists in model attributes OR if it's a translation-only field
                if ((array_key_exists($key, $this->attributes) || in_array($key, $translationOnlyFields)) && !is_null($value)) {
                    // Decode JSON fields if needed
                    if (in_array($key, $jsonFields) && is_string($value)) {
                        $decoded = json_decode($value, true);
                        if (json_last_error() === JSON_ERROR_NONE) {
                            $value = $decoded;
                        }
                    }
                    $attributesToSet[$key] = $value;
                }
            }

            if(!empty($attributesToSet)) {
                // Use setAttribute for fields that might not be in fillable
                foreach ($attributesToSet as $key => $value) {
                    $this->setAttribute($key, $value);
                }
            }
        }
    }

    /**
     * Cache for field types to avoid repeated schema queries
     * @var array
     */
    protected static $fieldTypeCache = [];

    /**
     * Cache for column listings per table to avoid repeated schema queries
     * @var array
     */
    protected static $columnListingCache = [];

    /**
     * Cache for all column types per table to avoid repeated SHOW COLUMNS queries
     * @var array
     */
    protected static $columnTypesCache = [];

    /**
     * Map MySQL column type to PHP type
     */
    protected static function mapColumnTypeToPhpType(string $type): string
    {
        if (str_contains($type, 'int')) {
            return 'integer';
        } elseif (str_contains($type, 'decimal') || str_contains($type, 'float') || str_contains($type, 'double')) {
            return 'decimal';
        } elseif (str_contains($type, 'datetime') || str_contains($type, 'timestamp')) {
            return 'datetime';
        } elseif (str_contains($type, 'date')) {
            return 'date';
        } elseif (str_contains($type, 'text')) {
            return 'text';
        } elseif (str_contains($type, 'varchar') || str_contains($type, 'char')) {
            return 'string';
        } elseif (str_contains($type, 'json')) {
            return 'json';
        } elseif (str_contains($type, 'boolean') || str_contains($type, 'tinyint(1)')) {
            return 'boolean';
        } else {
            return 'string';
        }
    }

    /**
     * Load all column types for a table at once and cache them
     */
    protected static function loadColumnTypesForTable(string $tableName): void
    {
        if (isset(static::$columnTypesCache[$tableName])) {
            return; // Already loaded
        }

        try {
            // Fetch all column types in a single query
            $columns = \DB::select("SHOW COLUMNS FROM `{$tableName}`");

            $types = [];
            foreach ($columns as $column) {
                $fieldName = $column->Field;
                $fieldType = static::mapColumnTypeToPhpType($column->Type);
                $cacheKey = "{$tableName}.{$fieldName}";
                static::$fieldTypeCache[$cacheKey] = $fieldType;
                $types[$fieldName] = $fieldType;
            }

            static::$columnTypesCache[$tableName] = $types;
        } catch (\Exception $e) {
            // If we can't load types, mark as loaded to avoid repeated failures
            static::$columnTypesCache[$tableName] = [];
        }
    }

    public static function getFieldType($field)
    {
        try {
            $tableName = (new static)->getTable();
            $cacheKey = "{$tableName}.{$field}";

            // Return cached value if available
            if (isset(static::$fieldTypeCache[$cacheKey])) {
                return static::$fieldTypeCache[$cacheKey];
            }

            // Load all column types for this table if not already loaded
            static::loadColumnTypesForTable($tableName);

            // Check cache again after loading
            if (isset(static::$fieldTypeCache[$cacheKey])) {
                return static::$fieldTypeCache[$cacheKey];
            }

            // Field not found in table
            static::$fieldTypeCache[$cacheKey] = null;
            return null;
        } catch (\Exception $e) {
            // Fallback to string type if we can't determine the actual type
            $tableName = (new static)->getTable();
            $cacheKey = "{$tableName}.{$field}";
            static::$fieldTypeCache[$cacheKey] = 'string';
            return 'string';
        }
    }

    public function translation($locale = null)
    {
        $locale = $locale ?: app()->getLocale();
        return $this->translations()->where('locale', $locale)->first();
    }

    public function translate($locale = null)
    {
        $locale = $locale ?: app()->getLocale();

        if (!method_exists($this, 'translations')) {
            return $this;
        }

        // Use loaded translations if available, otherwise query
        if ($this->relationLoaded('translations')) {
            $translation = $this->translations->where('locale', $locale)->first();
        } else {
            $translation = $this->translations()->where('locale', $locale)->first();
        }
        $this->updateAttributesFromTranslation($translation);

        // check all relations and if system has translation class, translate them
        $relations = $this->getRelationNames();

        foreach ($relations as $relation) {
            if($relation == 'translations') continue;

            if ($this->$relation instanceof \Illuminate\Database\Eloquent\Collection) {

                $translatedItems = $this->$relation->map(function ($relationItem) use ($locale) {

                    if (method_exists($relationItem, 'translations')) {
                        // Use loaded translations if available, otherwise query
                        if ($relationItem->relationLoaded('translations')) {
                            $translation = $relationItem->translations->where('locale', $locale)->first();
                        } else {
                            $translation = $relationItem->translations()->where('locale', $locale)->first();
                        }

                        if ($translation) {
                            $relationItem->updateAttributesFromTranslation($translation);
                        }
                    }

                    return $relationItem;
                });

                $relation = Str::snake($relation);

                $this->setRelation($relation, $translatedItems);
            }else{
                if (method_exists($this->$relation, 'translations')) {
                    // Use loaded translations if available, otherwise query
                    if ($this->$relation && $this->$relation->relationLoaded('translations')) {
                        $translation = $this->$relation->translations->where('locale', $locale)->first();
                    } else if ($this->$relation) {
                        $translation = $this->$relation->translations()->where('locale', $locale)->first();
                    } else {
                        $translation = null;
                    }
                    if ($translation) {
                        $this->$relation->updateAttributesFromTranslation($translation);
                    }
                }
            }
        }
        return $this;
    }
}


Activity::creating(function ($activity) {
    if (isset($activity->properties['old']) && isset($activity->properties['attributes']) && array_key_exists(
            'id',
            $activity->properties['old']
        ) && array_key_exists('id', $activity->properties['attributes'])) {
        if (is_null($activity->properties['old']['id']) && !is_null($activity->properties['attributes']['id'])) {
            $activity->event = 'created';
            $activity->description = 'created';
            $props = $activity->properties;
            unset($props['old']);
            $activity->properties = $props;
        }
    }

    if(isset($activity->properties['old']) && isset($activity->properties['attributes'])){
        $diff = BaseModel::array_diff_recursive($activity->properties['attributes'], $activity->properties['old']);
    }elseif(!isset($activity->properties['old']) && isset($activity->properties['attributes'])) {
        $diff = $activity->properties['attributes'];
    }elseif(isset($activity->properties['old']) && !isset($activity->properties['attributes'])) {
        $diff = $activity->properties['old'];
    }else{
        $diff = [];
    }

    $activity->properties_diff = json_encode($diff);
    $activity->changed_fields = implode(',',array_keys($diff));

    if (isset($activity->properties['old']) && isset($activity->properties['attributes'])) {
        $diff = BaseModel::array_diff_recursive($activity->properties['attributes'], $activity->properties['old']);
        if (count($diff) == 1 && array_key_exists('updated_at', $diff)) {
            return false;
        }
    }
});
