<?php

namespace App\Workforce\Support;

use LogicException;

/**
 * For ledgers and the audit trail: a line, once written, is never changed or removed. A mistake is put right by
 * writing another line that says so.
 */
trait AppendOnly
{
    public static function bootAppendOnly(): void
    {
        static::updating(fn ($line) => throw new LogicException(class_basename($line).' lines are never changed. Write a correcting line instead.'));
        static::deleting(fn ($line) => throw new LogicException(class_basename($line).' lines are never removed.'));
    }
}
