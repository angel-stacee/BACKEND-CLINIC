<?php

namespace App\Models\Concerns;

trait HasSoftDeleteUniqueToken
{
    public static function bootHasSoftDeleteUniqueToken(): void
    {
        static::deleting(function ($model) {
            if (! method_exists($model, 'isForceDeleting') || ! $model->isForceDeleting()) {
                $model->deleted_token = $model->getKey();
                $model->saveQuietly();
            }
        });

        static::restoring(function ($model) {
            $model->deleted_token = 0;
        });
    }
}
