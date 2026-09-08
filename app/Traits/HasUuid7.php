<?php

namespace App\Traits;

use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Generates time-ordered UUID version 7 primary keys.
 * App-side generation keeps IDs stable for offline-first sync.
 */
trait HasUuid7
{
    public function getIncrementing(): bool
    {
        return false;
    }

    public function getKeyType(): string
    {
        return 'string';
    }

    protected static function bootHasUuid7(): void
    {
        static::creating(function ($model) {
            if (empty($model->getKey())) {
                $model->setAttribute($model->getKeyName(), strtolower(Uuid::uuid7()->toString()));
            }
        });
    }
}