<?php

namespace OneMediaLabs\MixpostMcp;

use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;
use OneMediaLabs\MixpostMcp\Collection\ServiceCollection;
use OneMediaLabs\MixpostMcp\Exceptions\ServiceNotRegistered;
use OneMediaLabs\MixpostMcp\Models\Service as ServiceModel;
use OneMediaLabs\MixpostMcp\Services\FacebookService;
use OneMediaLabs\MixpostMcp\Services\LinkedInService;
use OneMediaLabs\MixpostMcp\Services\TenorService;
use OneMediaLabs\MixpostMcp\Services\TikTokService;
use OneMediaLabs\MixpostMcp\Services\TwitterService;
use OneMediaLabs\MixpostMcp\Services\UnsplashService;
use OneMediaLabs\MixpostMcp\Services\YouTubeService;
use OneMediaLabs\MixpostMcp\Support\Log;

class ServiceManager
{
    protected ?ServiceCollection $cacheServices = null;

    protected mixed $config;

    public function __construct(Container $container)
    {
        $this->config = $container->make('config');
    }

    protected function registeredServices(): array
    {
        return [
            FacebookService::class,
            TwitterService::class,
            LinkedInService::class,
            TikTokService::class,
            YouTubeService::class,
            UnsplashService::class,
            TenorService::class,
        ];
    }

    public function services(): ServiceCollection
    {
        if ($this->cacheServices) {
            return $this->cacheServices;
        }

        return $this->cacheServices = new ServiceCollection($this->registeredServices());
    }

    public function getServiceClass(string $name): ?string
    {
        $service = Arr::first($this->services()->getClasses(), function ($serviceClass) use ($name) {
            return $serviceClass::name() === $name;
        });

        if (! $service) {
            throw new ServiceNotRegistered($name);
        }

        return $service;
    }

    public function isActive(string|array|null $name = null): array|bool
    {
        if (is_string($name)) {
            return (bool) $this->get($name, 'active');
        }

        if (is_array($name)) {
            return array_reduce($name, function ($array, $serviceName) {
                $array[$serviceName] = $this->isActive($serviceName);

                return $array;
            }, []);
        }

        return array_reduce($this->services()->getCollection(), function ($array, $service) {
            $array[$service['name']] = $this->isActive($service['name']);

            return $array;
        }, []);
    }

    public function isConfigured(string|array|null $name = null): array|bool
    {
        if (is_string($name)) {
            $requiredInputs = array_keys(Arr::where($this->getServiceClass($name)::formRules(), function ($rules) {
                return in_array('required', $rules);
            }));

            $configuration = $this->get($name, 'configuration');

            return empty(array_filter($requiredInputs, function ($input) use ($configuration) {
                return empty(Arr::get($configuration, $input));
            }));
        }

        if (is_array($name)) {
            return array_reduce($name, function ($array, $serviceName) {
                $array[$serviceName] = $this->isConfigured($serviceName);

                return $array;
            }, []);
        }

        return array_reduce($this->services()->getCollection(), function ($array, $service) {
            $array[$service['name']] = $this->isConfigured($service['name']);

            return $array;
        }, []);
    }

    public function exposedConfiguration(string|array|null $name = null): array
    {
        if (is_string($name)) {
            return Arr::only($this->get($name, 'configuration'), $this->getServiceClass($name)::$exposedFormAttributes);
        }

        if (is_array($name)) {
            return array_reduce($name, function ($array, $serviceName) {
                $array[$serviceName] = $this->exposedConfiguration($serviceName);

                return $array;
            }, []);
        }

        return array_reduce($this->services()->getCollection(), function ($array, $service) {
            $array[$service['name']] = $this->exposedConfiguration($service['name']);

            return $array;
        }, []);
    }

    public function put(string $name, array $configuration, bool $active = false): void
    {
        Cache::put($this->resolveCacheKey($name), [
            'configuration' => Crypt::encryptString(json_encode($configuration)),
            'active' => $active,
        ]);
    }

    public function get(string $name, ?string $key = null)
    {
        // Mastodon service is not exists. Each Mastodon server has its own configuration.
        // Mastodon configuration is stored during connection process.
        $isMastodon = Str::startsWith($name, 'mastodon.');

        $defaultPayload = [
            'configuration' => $isMastodon ? [] : $this->getServiceClass($name)::form(),
            'active' => $isMastodon,
        ];

        $value = $this->getFromCache($name, function () use ($name, $defaultPayload) {
            $dbRecord = ServiceModel::where('name', $name)->first();

            try {
                $payload = $dbRecord ? [
                    'configuration' => array_merge($defaultPayload['configuration'], $dbRecord->configuration->toArray()),
                    'active' => $dbRecord->active ?? false,

                ] : $defaultPayload;

                $this->put($name, $payload['configuration'], $payload['active']);

                return $payload;
            } catch (DecryptException $exception) {
                $this->logDecryptionError($name, $exception);

                return $defaultPayload;
            }
        });

        // Decrypt the configuration from the cache
        if (! is_array($value['configuration'] ?? [])) {
            try {
                $value = array_merge($value, [
                    'configuration' => json_decode(Crypt::decryptString($value['configuration']), true),
                ]);
            } catch (DecryptException $exception) {
                $this->logDecryptionError($name, $exception);

                $value = $defaultPayload;
            }
        }

        if ($key) {
            return Arr::get($value, $key);
        }

        return $value;
    }

    public function all(): array
    {
        return array_reduce($this->services()->getCollection(), function ($array, $service) {
            $array[$service['name']] = $this->get($service['name']);

            return $array;
        }, []);
    }

    public function getFromCache(string $name, mixed $default = null)
    {
        return Cache::get($this->resolveCacheKey($name), $default);
    }

    public function forget($name): void
    {
        Cache::forget($this->resolveCacheKey($name));
    }

    public function forgetAll(): void
    {
        foreach ($this->services()->getNames() as $service) {
            $this->forget($service);
        }
    }

    protected function resolveCacheKey(string $name): string
    {
        return $this->config->get('mixpostmcp.cache_prefix').".services.$name";
    }

    protected function logDecryptionError(string $name, DecryptException $exception): void
    {
        Log::error("The application key cannot decrypt the service configuration: {$exception->getMessage()}", [
            'service_name' => $name,
        ]);
    }
}
