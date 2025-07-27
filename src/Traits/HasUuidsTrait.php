<?php

namespace Nexzan\Shared\Traits;

use Illuminate\Database\Eloquent\Concerns\HasUuids;


trait HasUuidsTrait
{
    use HasUuids;

    /**
     * Override the default UUID generator to return a UUID without hyphens.
     */
    public function newUniqueId()
    {
        return (string) newUuid();
    }

    /**
     * Override the default uuid validator to and return true.
     */
    protected function isValidUniqueId($value): bool
    {
        return true;
    }
}
