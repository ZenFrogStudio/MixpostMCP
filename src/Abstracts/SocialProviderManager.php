<?php

namespace OneMediaLabs\MixpostMcp\Abstracts;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Contracts\SocialProvider;
use InvalidArgumentException;

abstract class SocialProviderManager
{
    protected Container $container;

    protected mixed $config;

    protected mixed $values = [];

    public function __construct(Container $container)
    {
        $this->container = $container;
        $this->config = $container->make('config');
    }

    /**
     * The provider identifiers this installation can actually connect, mapped to their classes.
     * This is the single source of truth for provider availability — the accounts UI and
     * createConnection() both read it, so a network can never be offered and then fail.
     */
    abstract public function providers(): array;

    public function connect(string $provider, array $values = [])
    {
        $this->setValues($values);

        return $this->createConnection($provider);
    }

    protected function setValues(array $values): void
    {
        $this->values = $values;
    }

    private function createConnection(string $provider)
    {
        // Checked before the connect method runs, because a connect method names its provider
        // class directly: calling one for a provider that is not registered instantiates a class
        // that may not exist, which is a fatal error rather than a catchable one. Registered-ness
        // is the same test the accounts UI uses to decide what to offer.
        if (! array_key_exists($provider, $this->providers())) {
            throw new InvalidArgumentException("Provider [$provider] is not available.");
        }

        $method = 'connect'.Str::studly($provider).'Provider';

        if (method_exists($this, $method)) {
            return $this->$method();
        }

        throw new InvalidArgumentException("Provider [$provider] not supported.");
    }

    protected function buildConnectionProvider(string $provider, array $config): SocialProvider
    {
        $connection = (new $provider($this->container->make('request'), $config['client_id'], $config['client_secret'], $config['redirect'], array_merge($this->values, $config['values'] ?? [])));

        if (! $connection instanceof SocialProvider) {
            throw new \Exception('The provider must be an instance of SocialProvider');
        }

        return $connection;
    }
}
