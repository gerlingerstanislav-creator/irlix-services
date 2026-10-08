<?php
namespace App\Migration\Core;

/** Structured private diagnostics; never include raw rates or encrypted values. */
final class SourceRowConflict extends \DomainException
{
    public function __construct(public readonly string $reason, string $message, public readonly array $details = [])
    { parent::__construct($message); }
}
