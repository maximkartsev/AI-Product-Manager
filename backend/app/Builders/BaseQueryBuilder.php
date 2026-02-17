<?php

namespace App\Builders;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class BaseQueryBuilder extends Builder
{
    public function orderByRelation($relationName, $field, $orderDirection = 'asc') {
        $relation = $this->getRelation($relationName);
        $relatedTable = $relation->getRelated()->getTable();

        if(!Collection::make($this->getQuery()->joins)->pluck('table')->contains($relatedTable)){
            $this->leftJoin($relatedTable, $relation->getQualifiedForeignKeyName(), '=', $relation->getQualifiedOwnerKeyName());
        }

        $this->orderBy("$relatedTable.$field", $orderDirection);
    }
}

