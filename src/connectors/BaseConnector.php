<?php

namespace bymayo\vouch\connectors;

/**
 * Convenience base class. Connectors can extend this to inherit sensible
 * defaults — null icon, pull-only capability set, empty settings schema — and
 * override only what they need. Pure implementations of ConnectorInterface
 * are still welcome.
 */
abstract class BaseConnector implements ConnectorInterface
{
    public const CAPABILITY_PULL = 'pull';
    public const CAPABILITY_PUSH = 'push';
    public const CAPABILITY_INVITE = 'invite';

    public static function icon(): ?string
    {
        return null;
    }

    public static function capabilities(): array
    {
        return [
            self::CAPABILITY_PULL => true,
            self::CAPABILITY_PUSH => false,
            self::CAPABILITY_INVITE => false,
        ];
    }

    public static function settingsSchema(): array
    {
        return [];
    }
}
