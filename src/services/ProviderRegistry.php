<?php

namespace bymayo\vouch\services;

use bymayo\vouch\connectors\ConnectorInterface;
use bymayo\vouch\connectors\feefo\FeefoConnector;
use bymayo\vouch\connectors\google\GoogleConnector;
use bymayo\vouch\connectors\manual\ManualConnector;
use bymayo\vouch\connectors\reviewsio\ReviewsioConnector;
use bymayo\vouch\connectors\trustpilot\TrustpilotConnector;
use Craft;
use yii\base\Component;

/**
 * Single source of truth for which provider connectors are available on this
 * install. Connectors are instantiated lazily and cached for the request
 * lifetime, so `get('google')` always returns the same object.
 */
class ProviderRegistry extends Component
{
    /** @var class-string<ConnectorInterface>[] */
    private const BUILT_IN = [
        ManualConnector::class,
        GoogleConnector::class,
        TrustpilotConnector::class,
        FeefoConnector::class,
        ReviewsioConnector::class,
    ];

    /** @var array<string, ConnectorInterface> handle => instance */
    private array $_instances = [];

    /**
     * @return array<string, ConnectorInterface>
     */
    public function all(): array
    {
        foreach (self::BUILT_IN as $class) {
            $this->get($class::handle());
        }
        return $this->_instances;
    }

    public function get(string $handle): ?ConnectorInterface
    {
        if (isset($this->_instances[$handle])) {
            return $this->_instances[$handle];
        }

        foreach (self::BUILT_IN as $class) {
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
        return self::BUILT_IN;
    }
}
