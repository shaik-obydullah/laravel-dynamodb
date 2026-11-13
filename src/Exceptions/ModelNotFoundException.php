<?php

namespace Shaik\LaravelDynamoDb\Exceptions;

use RuntimeException;

class ModelNotFoundException extends RuntimeException
{
    public function __construct(string $model, mixed $key = null)
    {
        $key = $key === null ? '' : ' '.json_encode($key);

        parent::__construct("No query results for model [{$model}]{$key}.");
    }
}
