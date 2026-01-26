<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * UserOwned (baseline ownership enforcement, no sharding).
 *
 * This file is installed by `aios/v1/tools/bootstrap.sh`.
 */
trait UserOwned
{
    protected static function bootUserOwned(): void
    {
        if (!(bool) config('ownership.enabled', true)) {
            return;
        }

        if (!(bool) config('ownership.enforce_user_scope', true)) {
            return;
        }

        static::addGlobalScope('ownership_user_scope', function (Builder $builder) {
            $userId = static::currentUserId();
            if (!$userId) {
                return;
            }

            $model = $builder->getModel();
            if (method_exists($model, 'isOwnershipForcedGlobal') && $model->isOwnershipForcedGlobal()) {
                return;
            }

            $key = method_exists($model, 'getOwnershipKeyColumn')
                ? $model->getOwnershipKeyColumn()
                : (string) config('ownership.user_key', 'user_id');

            if (empty($key)) {
                return;
            }

            $builder->where($model->getTable() . '.' . $key, '=', $userId);
        });

        static::creating(function (Model $model) {
            $userId = static::currentUserId();
            if (!$userId) {
                return;
            }

            $key = method_exists($model, 'getOwnershipKeyColumn')
                ? $model->getOwnershipKeyColumn()
                : (string) config('ownership.user_key', 'user_id');

            if (empty($key)) {
                return;
            }

            $current = $model->getAttribute($key);

            if (is_null($current)) {
                $model->setAttribute($key, $userId);
            } elseif ((int) $current !== (int) $userId) {
                throw new \RuntimeException("Ownership key mismatch: cannot create {$model->getTable()} for another user.");
            }

            static::assertUserOwnedBelongsToConstraints($model);
        });

        static::updating(function (Model $model) {
            $key = method_exists($model, 'getOwnershipKeyColumn')
                ? $model->getOwnershipKeyColumn()
                : (string) config('ownership.user_key', 'user_id');

            if (empty($key)) {
                return;
            }

            if ($model->isDirty($key)) {
                throw new \RuntimeException("Ownership key is immutable: cannot change {$model->getTable()}.{$key}.");
            }

            static::assertUserOwnedBelongsToConstraints($model, onlyDirty: true);
        });
    }

    /**
     * @return array<string, class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public function userOwnedBelongsTo(): array
    {
        return [];
    }

    protected static function assertUserOwnedBelongsToConstraints(Model $model, bool $onlyDirty = false): void
    {
        if (!static::currentUserId()) {
            return;
        }

        if (!method_exists($model, 'userOwnedBelongsTo')) {
            return;
        }

        $map = $model->userOwnedBelongsTo();
        if (empty($map) || !is_array($map)) {
            return;
        }

        foreach ($map as $fkColumn => $relatedClass) {
            if (!is_string($fkColumn) || empty($fkColumn) || !is_string($relatedClass) || empty($relatedClass)) {
                continue;
            }

            if ($onlyDirty && !$model->isDirty($fkColumn)) {
                continue;
            }

            $id = $model->getAttribute($fkColumn);
            if (is_null($id) || $id === '') {
                continue;
            }

            /** @var class-string<Model> $relatedClass */
            $exists = $relatedClass::query()->whereKey($id)->exists();

            if (!$exists) {
                throw new \RuntimeException("Invalid reference: {$model->getTable()}.{$fkColumn} does not belong to the current user scope.");
            }
        }
    }

    public function getOwnershipKeyColumn(): string
    {
        return (string) config('ownership.user_key', 'user_id');
    }

    public function isOwnershipForcedGlobal(): bool
    {
        $forceGlobalTables = (array) config('ownership.force_global_tables', []);

        return in_array($this->getTable(), $forceGlobalTables, true);
    }

    protected static function currentUserId(): ?int
    {
        try {
            $id = auth()->id();
            if ($id) {
                return (int) $id;
            }
        } catch (\Throwable $e) {
            // ignore
        }

        return null;
    }
}

