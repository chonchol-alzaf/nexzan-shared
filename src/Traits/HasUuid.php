<?php

namespace Nexzan\Shared\Traits;


trait HasUuid
{
    public static function bootHasUuid()
    {
        static::creating(function ($model) {
            // শুধুমাত্র যদি ID সেট না থাকে
            if (empty($model->{$model->getKeyName()})) {
                $model->{$model->getKeyName()} = newUniqueId();
            }
        });
    }

    public function getKeyType()
    {
        return 'string';
    }

    public function getIncrementing()
    {
        return false;
    }

}
