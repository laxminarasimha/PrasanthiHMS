<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/** AI reading did not give a draft. The message is written for the person who asked. */
class AiReadingFailed extends RuntimeException
{
    /**
     * @param  bool  $sent  whether the pages had already left the hospital when it failed
     */
    public function __construct(string $message, public readonly bool $sent = false, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
