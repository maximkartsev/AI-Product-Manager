<?php

namespace App\Http\Controllers;

use App\Models\BaseModel;
use App\Models\UploadedFile;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator as Validator;

class BaseController extends Controller
{
    public function sendResponse($result, $message, $additionalFirstLevelFields = []): \Illuminate\Http\JsonResponse
    {
        $response = [];
        foreach ($additionalFirstLevelFields as $field => $value) {
            $response[$field] = $value;
        }

        $response['success'] = true;
        $response['data'] = $result;
        $response['message'] = $message;

        return response()->json($response, 200);
    }

    public function sendError($error, $errorMessages = [], $code = 404)
    {
        $response = [
            'success' => false,
            'message' => $error,
        ];

        if (str_contains($error, '|JSON_OBJECT|')) {
            $errorParts = explode('|JSON_OBJECT|', $error);
            $response['message'] = $errorParts[0];
            $additionalErrorMessages = json_decode($errorParts[1], true);

            foreach ($additionalErrorMessages as $field => $additionalErrorMessage) {
                $errorMessages[$field] = $additionalErrorMessage;
            }
        }

        if (!empty($errorMessages)) {
            $response['data'] = $errorMessages;
        }

        return response()->json($response, $code);
    }

    /**
     * @param mixed $orderStr
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param float|int $from
     * @param float|int $perPage
     * @return array
     */
    public function addCountQueryAndExecute(
        mixed                                 $orderStr,
        \Illuminate\Database\Eloquent\Builder $query,
        float|int                             $from,
        float|int                             $perPage,
                                              $allowedExtraFieldsToOrderBy = [],
                                              $forceSkipGroupBy = false,
                                              $skipAddId = false,
                                              $searchStr = null

    ): array
    {
        $needToAddGroupBy = false;

        list($table, $needToAddGroupBy) = $this->addOrdering($orderStr, $query, $needToAddGroupBy, $allowedExtraFieldsToOrderBy, $skipAddId, $searchStr);

        $queryCount = clone $query;

        $totalRows = $queryCount->count(['*']);

        $query->offset($from);
        $query->limit($perPage);


        if ($needToAddGroupBy && !$forceSkipGroupBy) {
            $primaryKey = $query->getModel()->getKeyName();
            $table = $query->getModel()->getTable();
            $query->addSelect($table . '.*');
            $query->groupBy($table . '.' . $primaryKey);
        }

        $items = $query->get();
        return [$totalRows, $items];
    }

    /**
     * @param Request $request
     * @return array
     */
    public function buildParamsFromRequest(Request $request, $query = null): array
    {
        $perPage = abs((int)$request->get('perPage', 50));

        $page = abs((int)$request->get('page', 1));

        $fieldsToSelect = $request->get('columns', ['*']);

        if (is_string($fieldsToSelect) && strpos($fieldsToSelect, ',')) {
            $fieldsToSelect = explode(',', $fieldsToSelect);
        }

        $searchStr = $request->get('search', null);

        $from = ($page - 1) * $perPage;

        if (!is_null($query)) {
            $table = $query->getModel()->getTable();

            if (is_array($fieldsToSelect)) {
                foreach ($fieldsToSelect as $i => $v) {
                    if ($v === '*') {
                        continue;
                    }
                    if (strpos($v, '.') === false) {
                        $fieldsToSelect[$i] = $table . '.' . $v;
                    }
                }
            }
        }

        return [$perPage, $page, $fieldsToSelect, $searchStr, $from];
    }

    /**
     * @param mixed $searchStr
     * @param Builder $query
     * @param $fields
     * @return void
     */
    public function addSearchCriteria(mixed $searchStr, \Illuminate\Database\Eloquent\Builder $query, $fields): void
    {
        if (is_string($searchStr) && strlen($searchStr) > 0) {
            $query->where(function ($q) use ($searchStr, $fields) {
                foreach ($fields as $field) {
                    if (str_contains($field, '>')) {
                        $fieldParts = explode('>', $field);
                        $field = array_pop($fieldParts);
                        $relationsString = implode('.', $fieldParts);

                        $q->orWhereHas($relationsString, function ($q) use ($field, $searchStr) {
                            $q->where($field, 'like', '%' . $searchStr . '%');
                        });
                    } else {
                        $q->orWhere($field, 'like', '%' . $searchStr . '%');
                    }
                }
            });
        }
    }

    /**
     * @param Request $request
     * @return mixed|UploadedFile
     */
    public function getUploadedFile(Request $request, $fieldName = 'file')
    {
        $file = $request->file($fieldName);
        $originalFileName = $file->getClientOriginalName();
        $extension = $file->getClientOriginalExtension();

        if (!$extension) {
            $extension = $file->getExtension();
        }

        $size = $file->getSize();
        $mimeType = $file->getClientMimeType();
        $path = $file->store('/files');

        $uploadedFile = UploadedFile::create([
            'name' => $originalFileName,
            'path' => $path,
            'extension' => $extension,
            'size' => $size,
            'storage' => 'local',
            'mime_type' => $mimeType,
        ]);
        return $uploadedFile;
    }


    /**
     * @param array $itemsForLazyLoad
     * @param mixed $fieldsToSelect
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return void
     */
    public function addLazyLoad(
        array                                 $itemsForLazyLoad,
        mixed                                 $fieldsToSelect,
        \Illuminate\Database\Eloquent\Builder $query
    ): void
    {
        $lazyLoadRelations = [];

        $table = $query->getModel()->getTable();
        foreach ($itemsForLazyLoad as $field => $relation) {
            if (in_array($field, $fieldsToSelect) || in_array('*', $fieldsToSelect)) {
                $lazyLoadRelations[] = $relation;
                $query->addSelect($table . '.' . $field . ' as ' . $field);
            }
        }

        if (!empty($lazyLoadRelations)) {
            $query->with($lazyLoadRelations);
        }
    }

    public function getAvailableFilers(Request $request)
    {
        // if there is no class in request then extract class from controller name
        $class = $request->get('class', null);

        $search = $request->get('search', null);

        if (is_null($class) || empty($class)) {
            return $this->sendError(trans('Class not specified'), [], 400);
        }

        $originalClass = $class;

        // Ensure class is not empty before constructing full class name
        if (empty($class)) {
            return $this->sendError(trans('Invalid class name'), [], 400);
        }

        $class = 'App\Models\\' . $class;

        if (!((new $class) instanceof BaseModel) && !(new $class) instanceof User) {
            return $this->sendError(trans('Class not found'), [], 400);
        }

        $fillableFields = (new $class())->getFillable();

        if ($class != 'App\\Models\\User' && $class::hasTimestamps()) {
            $fillableFields[] = 'created_at';
            $fillableFields[] = 'updated_at';
        }

        $fillableFields[] = 'id';


        if ($search) {
            foreach ($fillableFields as $i => $fillableField) {
                $label = $fillableField;

                if (str_ends_with($label, '_id')) {
                    $label = substr($label, 0, -3);
                }

                $label = str_replace('_', ' ', $label);

                $label = ucwords($label);

                $label = trans($label);


                if (!str_contains(mb_strtolower($fillableField), mb_strtolower($search)) && !str_contains(
                        mb_strtolower($label),
                        mb_strtolower($search)
                    )) {
                    unset($fillableFields[$i]);
                }
            }
        }

        $filters = [];


        foreach ($fillableFields as $field) {
            $label = $field;

            $containId = false;

            if (str_ends_with($label, '_id')) {
                $label = substr($label, 0, -3);
                $containId = true;
            }

            $label = str_replace('_', ' ', $label);

            $label = ucwords($label);

            $label = trans($label);

            if ($containId || !is_subclass_of($class, BaseModel::class)) {
                $operators = ['=', 'in', 'notin', 'not', 'isnull', 'notnull', 'doesnthave'];
            } else {
                /** @var BaseModel $class */

                $type = $class::getFieldType($field);

                switch ($type) {
                    case 'integer':
                    case 'bigint':
                        $operators = [
                            '=',
                            'in',
                            'notin',
                            'not',
                            'greater',
                            'less',
                            'greaterorequal',
                            'lessorequal',
                            'isnull',
                            'notnull',
                            'doesnthave',
                            'between'
                        ];
                        break;
                    case 'decimal':
                    case 'date':
                    case 'datetime':
                        $operators = [
                            '=',
                            'in',
                            'notin',
                            'not',
                            'greater',
                            'less',
                            'greaterorequal',
                            'lessorequal',
                            'isnull',
                            'notnull',
                            'between'
                        ];
                        break;
                    case 'boolean':
                        $operators = ['=', 'in', 'notin', 'not', 'isnull', 'notnull'];
                        break;
                    case 'string':
                    case 'text':
                        $operators = ['=', 'in', 'notin', 'not', 'like', 'isnull', 'notnull'];
                        break;
                    default:
                        $operators = [
                            '=',
                            'in',
                            'notin',
                            'not',
                            'greater',
                            'less',
                            'greaterorequal',
                            'lessorequal',
                            'like',
                            'isnull',
                            'notnull',
                        ];
                        break;
                }
            }


            $filters[] = [
                'field' => $field,
                'label' => mb_ucfirst($label),
                'type' => $type ?? 'string',
                'operators' => $operators
            ];
        }

        return $this->sendResponse(['filters' => $filters], null);
    }

    public function getAvailableColumns(Request $request)
    {
        $class = $request->get('class', null);

        if (is_null($class) || empty($class)) {
            return $this->sendError(trans('Class not specified'), [], 400);
        }

        if (empty($class)) {
            return $this->sendError(trans('Invalid class name'), [], 400);
        }

        $class = 'App\Models\\' . $class;

        if (!((new $class) instanceof BaseModel) && !(new $class) instanceof User) {
            return $this->sendError(trans('Class not found'), [], 400);
        }

        $fillableFields = (new $class())->getFillable();

        if ($class != 'App\\Models\\User' && $class::hasTimestamps()) {
            $fillableFields[] = 'created_at';
            $fillableFields[] = 'updated_at';
        }

        $fillableFields[] = 'id';

        $fillableFields = array_unique($fillableFields);
        sort($fillableFields);

        $columns = [];
        $model = new $class();
        $addedKeys = [];

        foreach ($fillableFields as $field) {
            $key = $field;
            $label = $field;

            if (str_ends_with($label, '_id')) {
                $relationName = substr($label, 0, -3);
                $relationMethod = \Str::camel($relationName);

                if (method_exists($model, $relationMethod)) {
                    $key = $relationName;
                    $label = $relationName;
                }
            }

            $label = str_replace('_', ' ', $label);
            $label = ucwords($label);

            if (!in_array($key, $addedKeys, true)) {
                $columns[] = [
                    'key' => $key,
                    'label' => $label,
                ];
                $addedKeys[] = $key;
            }
        }

        return $this->sendResponse(['columns' => $columns], null);
    }

    public function getFilterOptions(Request $request)
    {
        // if there is no class in request then extract class from controller name
        $class = $request->get('class', null);
        $field = $request->get('field', null);
        $fieldValue = $request->get('field_value', null);
        $limit = $request->get('limit', 20);
        $search = $request->get('search', null);


        $needToFetchRelationName = false;
        // if ends with _id then remove it
        if (str_ends_with($field, '_id')) {
            $needToFetchRelationName = true;
        }

        if (is_null($class)) {
            return $this->sendError(trans('Class not specified'), [], 400);
        }

        $class = 'App\Models\\' . $class;

        if (!((new $class) instanceof BaseModel) && !(new $class) instanceof User) {
            return $this->sendError(trans('Class not found'), [], 400);
        }


        if (is_null($field)) {
            return $this->sendError(trans('Field not specified'), [], 400);
        }


        $query = $class::query()->select($field);

        if (!is_null($search)) {
            $query->where($field, 'like', '%' . $search . '%');
        }

        if (!is_null($fieldValue)) {
            $query->where($field, $fieldValue);
            $limit = 1;
        }


        $subQuery = $query->distinct()->orderBy($field, 'asc');

        // Main query with limit applied on the subquery
        $options = \DB::table(\DB::raw("({$subQuery->toSql()}) as sub"))
            ->mergeBindings($subQuery->getQuery()) // Merge bindings from subquery
            ->limit($limit)
            ->get()
            ->pluck($field)
            ->toArray();


        foreach ($options as $i => $option) {
            $options[$i] = [
                'id' => $option,
                'name' => $option
            ];
        }

        if ($needToFetchRelationName) {
            $relationName = substr($field, 0, -3);
            $relationName = \Str::camel($relationName);

            if ($field == 'make_id') {
                $relationName = 'carMake';
            }

            if (method_exists($class, $relationName)) {
                // Subquery for distinct values
                $extendedOptionsQuery = $class::query()
                    ->select($field)
                    ->with($relationName)
                    ->distinct()
                    ->orderBy($field, 'asc');

                if (!is_null($fieldValue)) {
                    $extendedOptionsQuery->where($field, $fieldValue);
                }

                $extendedOptions = $extendedOptionsQuery->get();
                $options = [];

                foreach ($extendedOptions as $extendedOption) {
                    if (count($options) >= $limit) {
                        break;
                    }
                    // Fetch the relation data
                    $relatedModel = $class::with($relationName)
                        ->where($field, $extendedOption->$field)
                        ->first();


                    if ($relatedModel && $relatedModel->$relationName && (is_null($search) || stripos(
                                $relatedModel->$relationName->getName(),
                                $search
                            ) !== false)) {
                        $options[] = [
                            'id' => $relatedModel->$field,
                            'name' => $relatedModel->$relationName->getName()
                        ];
                    }
                }
            }
        }


        return $this->sendResponse(['options' => $options], null);
    }

    protected function extractFilters(Request $request, string $class, $fillableFields = [])
    {
        if (empty($fillableFields)) {
            /** @var BaseModel $class */
            $fillableFields = $class::getFillableFieldNames();

            if ($class != 'App\\Models\\User' && $class::hasTimestamps()) {
                $fillableFields[] = 'created_at';
                $fillableFields[] = 'updated_at';
            }
        }

        $fillableFields[] = 'id';

        $input = $request->all();

        foreach ($input as $inputKey => $inputValue) {
            if (in_array($inputKey, ['page', 'perPage', 'order', 'search'])) {
                unset($input[$inputKey]);
            }
        }

        $fieldTypes = [];

        if((new $class) instanceof BaseModel) {
            // Only get field types for fields that are actually being used in filters
            // This avoids unnecessary schema queries for unused fields
            $fieldsToCheck = [];
            foreach ($input as $fieldName => $values) {
                $fieldParts = explode(':', $fieldName);
                $field = $fieldParts[0];
                $fieldPathParts = explode('>', $field);
                $field = array_pop($fieldPathParts);

                if (!in_array($field, $fieldsToCheck) && in_array($field, $fillableFields)) {
                    $fieldsToCheck[] = $field;
                }
            }

            // Always check common fields that might be used
            foreach (['id', 'created_at', 'updated_at'] as $commonField) {
                if (!in_array($commonField, $fieldsToCheck) && in_array($commonField, $fillableFields)) {
                    $fieldsToCheck[] = $commonField;
                }
            }

            foreach ($fieldsToCheck as $field) {
                $fieldTypes[$field] = $class::getFieldType($field);
            }
        }

        $filters = [];

        foreach ($input as $fieldName => $values) {
            $fieldParts = explode(':', $fieldName);

            $field = $fieldParts[0];

            $operator = $fieldParts[1] ?? '=';

            $fieldPathParts = explode('>', $field);

            $field = array_pop($fieldPathParts);
            $relations = $fieldPathParts;

            if ($operator == 'doesnthave') {
                $relations = [$field];
            }

            if (count($relations) === 0 && !in_array($field, $fillableFields)) {
                continue;
            }

            switch ($operator) {
                case 'in':
                case 'notin':
                case 'between':
                    $values = explode(',', $values);

                    foreach ($values as $i => $value) {

                        if ($value == 'null') {
                            $values[$i] = null;
                        }elseif($fieldTypes[$field]??'' == 'datetime' && is_string($value)){

                            $format = getDateFormat($value);

                            if(in_array($format, ['Y-m-d', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd/m/Y'])){

                                if($i == 0){
                                    $values[$i] = Carbon::parse($value)->format('Y-m-d 00:00:00');
                                }elseif($i==1){
                                    $values[$i] = Carbon::parse($value)->format('Y-m-d 23:59:59');
                                }
                            }
                        }
                    }
                    break;
                case 'not':
                case 'greater':
                case 'less':
                case 'greaterorequal':
                case 'lessorequal':
                case 'like':
                case 'isnull':
                case 'notnull':
                case 'doesnthave':
                    break;
                default:
                    $operator = '=';

                    if($fieldTypes[$field]??'' == 'datetime' && is_string($values)){

                        $format = getDateFormat($values);

                        if(in_array($format, ['Y-m-d', 'Y-m-d', 'd-m-Y', 'm/d/Y', 'Y/m/d', 'd/m/Y'])){
                            $operator = 'between';
                            $fromDateTime = Carbon::parse($values)->format('Y-m-d 00:00:00');
                            $toDateTime = Carbon::parse($values)->format('Y-m-d 23:59:59');
                            $values = [$fromDateTime, $toDateTime];
                        }
                    }
                    break;
            }

            $filter = [
                'field' => $field,
                'operator' => $operator,
                'values' => $values,
            ];

            if (count($relations)) {
                $filter['relations'] = $relations;
            }

            $filters[] = $filter;
        }

        return $filters;
    }

    protected function addFiltersCriteria(\Illuminate\Database\Eloquent\Builder $query, array $filters, string $class)
    {
        foreach ($filters as $filter) {
            if (isset($filter['relations'])) {
                $relationsString = implode('.', $filter['relations']);

                if ($filter['operator'] == 'doesnthave') {
                    $query->doesntHave($relationsString);
                } else {
                    $query->whereHas($relationsString, function ($q) use ($filter) {
                        $field = $filter['field'];
                        $values = $filter['values'];

                        switch ($filter['operator']) {
                            case 'in':
                                if (in_array(null, $values)) {
                                    $values = array_diff($values, [null]);

                                    $q->where(function ($q) use ($field, $values) {
                                        $q->orWhereNull($field);
                                        if (count($values) > 0) {
                                            $q->orWhereIn($field, $values);
                                        }
                                    });
                                } else {
                                    $q->whereIn($field, $values);
                                }
                                break;
                            case 'notin':
                                $q->whereNotIn($field, $values);
                                break;
                            case 'not':
                                $q->whereNot($field, $values);
                                break;
                            case 'between':
                                $q->whereBetween($field, $values);
                                break;
                            case 'greater':
                                $q->where($field, '>', $values);
                                break;
                            case 'less':
                                $q->where($field, '<', $values);
                                break;
                            case 'greaterorequal':
                                $q->where($field, '>=', $values);
                                break;
                            case 'lessorequal':
                                $q->where($field, '<=', $values);
                                break;
                            case 'like':
                                $q->where($field, 'like', '%' . $values . '%');
                                break;
                            case 'isnull':
                                $q->whereNull($field);
                                break;
                            case 'notnull':
                                $q->whereNotNull($field);
                                break;
                            case '=':
                                $q->where($field, '=', $values);
                                break;
                            default:
                                throw new \Exception(trans('Unsupported operator') . ': ' . $filter['operator']);
                                break;
                        }
                    });
                }
            } else {
                $field = $filter['field'];
                $values = $filter['values'];

                if (in_array($field, ['created_at', 'updated_at'])) {
                    if (is_array($values)) {
                        $values = array_map(function ($value) {
                            return Carbon::parse($value)->format('Y-m-d H:i:s');
                        }, $values);
                    } else {
                        $values = Carbon::parse($values)->format('Y-m-d H:i:s');
                    }
                }

                switch ($filter['operator']) {
                    case 'in':
                        if (in_array(null, $values)) {
                            $values = array_diff($values, [null]);

                            $query->where(function ($q) use ($field, $values) {
                                $q->orWhereNull($field);
                                if (count($values) > 0) {
                                    $q->orWhereIn($field, $values);
                                }
                            });
                        } else {
                            $query->whereIn($field, $values);
                        }
                        break;
                    case 'notin':
                        $query->whereNotIn($field, $values);
                        break;
                    case 'not':
                        $query->whereNot($field, $values);
                        break;
                    case 'between':
                        $query->whereBetween($field, $values);
                        break;
                    case 'greater':
                        $query->where($field, '>', $values);
                        break;
                    case 'less':
                        $query->where($field, '<', $values);
                        break;
                    case 'greaterorequal':
                        $query->where($field, '>=', $values);
                        break;
                    case 'lessorequal':
                        $query->where($field, '<=', $values);
                        break;
                    case 'like':
                        $query->where($field, 'like', '%' . $values . '%');
                        break;
                    case 'isnull':
                        $query->whereNull($field);
                        break;
                    case 'notnull':
                        $query->whereNotNull($field);
                        break;
                    case '=':
                        $query->where($field, '=', $values);
                        break;
                    default:
                        throw new \Exception(trans('Unsupported operator') . ': ' . $filter['operator']);
                        break;
                }
            }
        }
    }

    /**
     * Auto-discover FK relations whose related model has a "name" field.
     * Returns entries like ["category>name"] for use with addSearchCriteria().
     */
    protected function getRelationSearchFields(string $class): array
    {
        $model = new $class();
        $fields = [];

        foreach ($model->getFillable() as $field) {
            if (str_ends_with($field, '_id')) {
                $relationName = substr($field, 0, -3);
                $relationMethod = \Str::camel($relationName);

                if (method_exists($model, $relationMethod)) {
                    try {
                        $related = $model->$relationMethod()->getRelated();
                        if (in_array('name', $related->getFillable())) {
                            $fields[] = $relationMethod . '>name';
                        }
                    } catch (\Throwable $e) {
                        // Not a valid relation — skip
                    }
                }
            }
        }

        return $fields;
    }

    /**
     * @param mixed $orderStr
     * @param Builder $query
     * @param bool $needToAddGroupBy
     * @return array
     */
    protected function addOrdering(mixed $orderStr, Builder $query, bool $needToAddGroupBy, $allowedExtraFieldsToOrderBy = [], $skipAddId = false, $searchStr = null): array
    {
        $table = $query->getModel()->getTable();
        $pkName = $query->getModel()->getKeyName();

        if (is_string($orderStr) && strlen($orderStr) > 0) {
            $orderParts = explode(',', $orderStr);

            if (!$skipAddId) {
                $query->addSelect($table . '.' . $pkName . ' as id');
            }

            foreach ($orderParts as $orderPart) {
                [$orderBy, $orderDirection] = explode(':', $orderPart);

                $orderDirection = (strtolower($orderDirection) == 'desc') ? 'desc' : 'asc';

                if (strpos($orderBy, '.') !== false) {
                    // order by a relation field
                    [$relationName, $relationField] = explode('.', $orderBy);
                    $query->orderByRelation($relationName, $relationField, $orderDirection);
                    $needToAddGroupBy = true;
                } else {
                    // check if order by is a field of the main entity
                    $fillableFields = $query->getModel()->getFillable();
                    $fillableFields[] = 'id';
                    $fillableFields[] = 'created_at';
                    $fillableFields[] = 'updated_at';

                    if (str_starts_with($orderBy, 'exact_match_in[') && str_ends_with($orderBy, ']') && $searchStr) {
                        $fields = substr($orderBy, 15, -1);
                        $fields = explode(';', $fields);

                        $fieldsToSort = [];

                        foreach ($fields as $field) {
                            if (in_array($field, $fillableFields) || in_array($field, $allowedExtraFieldsToOrderBy)) {
                                $fieldsToSort[] = $field;
                            }
                        }

                        if (count($fieldsToSort) > 0) {
                            $bindings = [];
                            $orderRawStr = '(' . implode(' = ? OR ', $fieldsToSort) . ' = ? ) DESC';

                            foreach ($fieldsToSort as $fieldToSort) {
                                $bindings[] = $searchStr;
                            }

                           $query->orderByRaw(\DB::raw($orderRawStr), array_values($bindings));

                        }
                    } else {
                        if (in_array($orderBy, $fillableFields) || in_array($orderBy, $allowedExtraFieldsToOrderBy)) {
                            $query->orderBy($orderBy, $orderDirection);
                        } else {
                            // Auto-resolve FK relation: e.g. "category" → "category.name"
                            $model = $query->getModel();
                            $relationMethod = \Str::camel($orderBy);
                            if (method_exists($model, $relationMethod)) {
                                try {
                                    $related = $model->$relationMethod()->getRelated();
                                    $displayField = in_array('name', $related->getFillable()) ? 'name' : null;
                                    if ($displayField) {
                                        $query->orderByRelation($relationMethod, $displayField, $orderDirection);
                                    }
                                } catch (\Throwable $e) {
                                    // Not a valid relation — skip silently
                                }
                            }
                        }
                    }
                }
            }
        }
        return array($table, $needToAddGroupBy);
    }

    public function getAvailableLabelsForClass($class): \Illuminate\Http\JsonResponse
    {

        $class = 'App\Models\\' . $class;

        if (!class_exists($class)) {
            return $this->sendError(trans('Class not found'), [], 400);
        }

        if (!((new $class) instanceof BaseModel)) {
            return $this->sendError(trans('Class not found'), [], 400);
        }

        // check if class has getLabelsList method
        if (!method_exists($class, 'getLabelsList')) {
            return $this->sendError(trans('Method getLabelsList not found'), [], 400);
        }

        $labels = $class::getLabelsList();

        return $this->sendResponse(['labels' => $labels], null);

    }
}
