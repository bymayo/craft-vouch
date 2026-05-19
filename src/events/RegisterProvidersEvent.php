<?php

namespace bymayo\vouch\events;

use bymayo\vouch\connectors\ConnectorInterface;
use yii\base\Event;

/**
 * Fired by `ProviderRegistry` so plugins can add connector classes. Mirrors
 * Craft's `RegisterComponentTypesEvent` convention.
 *
 * Example:
 *
 *     Event::on(
 *         ProviderRegistry::class,
 *         ProviderRegistry::EVENT_REGISTER_PROVIDERS,
 *         function (RegisterProvidersEvent $event) {
 *             $event->types[] = MyConnector::class;
 *         },
 *     );
 */
class RegisterProvidersEvent extends Event
{
    /** @var class-string<ConnectorInterface>[] */
    public array $types = [];
}
