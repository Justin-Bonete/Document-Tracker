<?php
namespace App\Exceptions;

use App\Support\Api;
use RuntimeException;

/** Business-rule violation. Renders itself as the blueprint's JSON error envelope; rolls back the transaction. */
class WorkflowException extends RuntimeException
{
    public function __construct(public string $errorCode, string $message, public int $httpStatus = 409)
    {
        parent::__construct($message);
    }

    public function render()
    {
        return Api::error($this->errorCode, $this->getMessage(), $this->httpStatus);
    }

    public function report(): bool
    {
        return true;   // expected business errors: don't flood the log
    }
}
