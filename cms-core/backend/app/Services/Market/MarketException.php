<?php

namespace App\Services\Market;

/** Domain error with an HTTP status + optional details payload for the K8 route block. */
class MarketException extends \RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $status = 422,
        public readonly ?array $details = null,
    ) {
        parent::__construct($message);
    }
}
