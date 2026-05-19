<?php

namespace bymayo\vouch\services;

final class SyncResult
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        public readonly bool $ok,
        public readonly string $message,
        public readonly int $created = 0,
        public readonly int $updated = 0,
        public readonly int $skipped = 0,
        public readonly array $errors = [],
    ) {
    }

    public static function error(string $message): self
    {
        return new self(ok: false, message: $message, errors: [$message]);
    }

    public static function skipped(string $message): self
    {
        return new self(ok: true, message: $message);
    }
}
