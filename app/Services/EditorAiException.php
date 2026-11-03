<?php

namespace App\Services;

use RuntimeException;
use Throwable;

/**
 * A failure the editor can explain to a person.
 *
 * Carries a code so the controller can map it to the right HTTP status: an
 * unconfigured site is a 503 (the feature is temporarily unavailable), a
 * provider failure is a 502 (the upstream misbehaved), and a storage failure
 * is a 500. Turning every one of those into a generic 500 would leave the
 * front end unable to say anything more useful than "something went wrong".
 */
class EditorAiException extends RuntimeException
{
    /** No provider key is configured for this site. */
    public const NOT_CONFIGURED = 'not_configured';

    /** The provider was reachable but failed, timed out, or refused. */
    public const PROVIDER_FAILED = 'provider_failed';

    /** The model responded, but not in a shape that could be used. */
    public const MALFORMED_RESPONSE = 'malformed_response';

    /** The generated image could not be written to disk. */
    public const STORAGE_FAILED = 'storage_failed';

    public function __construct(
        string $message,
        private readonly string $reason = self::PROVIDER_FAILED,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    /**
     * The machine-readable reason, sent to the front end so it can decide
     * whether to retry, prompt for configuration, or give up.
     *
     * Kept private with an accessor because `Exception::$code` already holds
     * the integer code; overloading it with a string would break the parent
     * class contract.
     */
    public function reason(): string
    {
        return $this->reason;
    }

    /**
     * The HTTP status that best describes this failure.
     */
    public function status(): int
    {
        return match ($this->reason) {
            self::NOT_CONFIGURED => 503,
            self::PROVIDER_FAILED => 502,
            self::MALFORMED_RESPONSE => 502,
            self::STORAGE_FAILED => 500,
            default => 500,
        };
    }
}
