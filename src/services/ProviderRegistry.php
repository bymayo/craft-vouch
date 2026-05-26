<?php

namespace bymayo\vouch\services;

use bymayo\vouch\connectors\ConnectorInterface;
use bymayo\vouch\events\RegisterProvidersEvent;
use Craft;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Single source of truth for which provider connectors are available on this
 * install. Built-in providers are appended by Vouch itself in Phases 2 and 3;
 * third-party plugins can register their own by subscribing to
 * `EVENT_REGISTER_PROVIDERS` and pushing a fully-qualified class name onto the
 * event's `types` array (mirrors Craft's element/widget registration pattern).
 *
 * Connectors are instantiated lazily and cached for the request lifetime, so
 * `get('google')` always returns the same object.
 */
class ProviderRegistry extends Component
{
    /** Event fired during plugin init so other plugins can add connectors. */
    public const EVENT_REGISTER_PROVIDERS = 'registerProviders';

    /** @var class-string<ConnectorInterface>[] */
    private array $_classes = [];

    /** @var array<string, ConnectorInterface> handle => instance */
    private array $_instances = [];

    private bool $_resolved = false;

    /**
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        $this->resolve();
        foreach ($this->_classes as $class) {
            $this->get($class::handle());
        }
        return $this->_instances;
    }

    public function get(string $handle): ?ConnectorInterface
    {
        $this->resolve();

        if (isset($this->_instances[$handle])) {
            return $this->_instances[$handle];
        }

        foreach ($this->_classes as $class) {
            if ($class::handle() === $handle) {
                /** @var ConnectorInterface $instance */
                $instance = Craft::createObject($class);
                return $this->_instances[$handle] = $instance;
            }
        }

        return null;
    }

    public function has(string $handle): bool
    {
        return $this->get($handle) !== null;
    }

    /**
     * Returns connector classes in registration order - useful for picker UIs
     * that want a stable list without instantiating every connector.
     *
     * @return class-string<ConnectorInterface>[]
     */
    public function classes(): array
    {
        $this->resolve();
        return $this->_classes;
    }

    private function resolve(): void
    {
        if ($this->_resolved) {
            return;
        }
        $this->_resolved = true;

        $event = new RegisterProvidersEvent();
        $this->trigger(self::EVENT_REGISTER_PROVIDERS, $event);

        foreach ($event->types as $class) {
            if (!is_string($class) || !is_subclass_of($class, ConnectorInterface::class)) {
                throw new InvalidConfigException(sprintf(
                    'Connector "%s" must implement %s.',
                    is_string($class) ? $class : gettype($class),
                    ConnectorInterface::class,
                ));
            }
            $this->_classes[] = $class;
        }
    }
}
