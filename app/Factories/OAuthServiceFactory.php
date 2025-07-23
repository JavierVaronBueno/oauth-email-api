<?php

namespace App\Factories;

use App\Contracts\OAuthServiceInterface;
use App\Models\LfVendorEmailConfiguration;
use App\Services\MicrosoftOAuthService;
use App\Services\GoogleOAuthService;
use App\Exceptions\OAuthException;
use InvalidArgumentException;

/**
 * Factory for creating OAuth2.0 service instances.
 *
 * Implements the Factory design pattern to create instances of OAuth2.0 services
 * based on the API provider specified in the configuration.
 *
 * This factory abstracts the creation of complex objects and allows
 * for easy extension to support new API providers.
 */
class OAuthServiceFactory
{
    /**
     * Mapping of providers to their respective service classes.
     *
     * @var array<string, string>
     */
    private static array $providerServices = [
        LfVendorEmailConfiguration::PROVIDER_MICROSOFT => MicrosoftOAuthService::class,
        LfVendorEmailConfiguration::PROVIDER_GOOGLE => GoogleOAuthService::class,
    ];

    /**
     * Cached service instances to prevent multiple object creations.
     *
     * @var array<string, OAuthServiceInterface>
     */
    private static array $serviceInstances = [];

    /**
     * Creates an instance of an OAuth2.0 service based on the provider.
     *
     * This method ensures that only one instance of a service is created per provider
     * by caching previously created instances.
     *
     * @param string $provider The name of the provider (e.g., 'microsoft' or 'google').
     * @return OAuthServiceInterface The OAuth2.0 service instance.
     * @throws InvalidArgumentException If the provider is not valid.
     * @throws OAuthException If the service cannot be created or does not implement the required interface.
     */
    public static function create(string $provider): OAuthServiceInterface
    {
        $provider = strtolower(trim($provider));

        if (!self::isValidProvider($provider)) {
            throw new InvalidArgumentException("Invalid OAuth2.0 provider: {$provider}");
        }

        // Return cached instance if it exists
        if (isset(self::$serviceInstances[$provider])) {
            return self::$serviceInstances[$provider];
        }

        try {
            $serviceClass = self::$providerServices[$provider];
            $service = new $serviceClass();

            if (!$service instanceof OAuthServiceInterface) {
                throw new OAuthException("Service for {$provider} does not implement OAuthServiceInterface");
            }

            // Cache the instance for future use
            self::$serviceInstances[$provider] = $service;

            return $service;
        } catch (\Exception $e) {
            // Catch any generic exception during service instantiation and re-throw as OAuthException
            throw new OAuthException("Error creating OAuth2.0 service for {$provider}: " . $e->getMessage(), $e->getCode(), null, null, $e);
        }
    }

    /**
     * Creates an instance of an OAuth2.0 service based on a configuration model.
     *
     * Extracts the provider API from the given configuration and delegates to the `create` method.
     *
     * @param LfVendorEmailConfiguration $config The vendor email configuration.
     * @return OAuthServiceInterface The OAuth2.0 service instance.
     * @throws InvalidArgumentException If the configuration does not specify an API provider.
     * @throws OAuthException If the service cannot be created.
     */
    public static function createFromConfig(LfVendorEmailConfiguration $config): OAuthServiceInterface
    {
        if (!$config->vec_provider_api) {
            throw new InvalidArgumentException("Configuration does not specify an API provider");
        }

        return self::create($config->vec_provider_api);
    }

     /**
     * Creates an instance of an OAuth2.0 service based on a configuration UID.
     *
     * Retrieves the `LfVendorEmailConfiguration` model by its unique identifier
     * and then delegates to `createFromConfig`.
     *
     * @param int $uid The unique identifier of the configuration.
     * @return OAuthServiceInterface The OAuth2.0 service instance.
     * @throws InvalidArgumentException If no configuration is found for the given UID.
     * @throws OAuthException If the service cannot be created.
     */
    public static function createFromUid(int $uid): OAuthServiceInterface
    {
        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw new InvalidArgumentException("Configuration with UID: {$uid} not found.");
        }

        return self::createFromConfig($config);
    }

    /**
     * Gets all available OAuth2.0 services.
     *
     * This method attempts to create and return an instance for each registered provider.
     *
     * @return array<string, OAuthServiceInterface> A map of provider names to OAuth2.0 service instances.
     */
    public static function getAllServices(): array
    {
        $services = [];

        foreach (array_keys(self::$providerServices) as $provider) {
            $services[$provider] = self::create($provider);
        }

        return $services;
    }

    /**
     * Checks if a given provider is valid.
     *
     * @param string $provider The name of the provider.
     * @return bool True if the provider is valid, false otherwise.
     */
    public static function isValidProvider(string $provider): bool
    {
        return array_key_exists($provider, self::$providerServices);
    }

    /**
     * Gets all available provider names.
     *
     * @return array<int, string> A list of available provider names.
     */
    public static function getAvailableProviders(): array
    {
        return array_keys(self::$providerServices);
    }

    /**
     * Registers a new OAuth2.0 service provider with its corresponding class.
     *
     * This method allows for dynamic addition of new OAuth providers to the factory.
     * It validates that the service class exists and implements `OAuthServiceInterface`.
     *
     * @param string $provider The name of the provider to register.
     * @param string $serviceClass The fully qualified class name of the service.
     * @throws InvalidArgumentException If the provider or service class is invalid.
     */
    public static function registerProvider(string $provider, string $serviceClass): void
    {
        if (empty($provider) || empty($serviceClass)) {
            throw new InvalidArgumentException("Provider and service class cannot be empty");
        }

        if (!class_exists($serviceClass)) {
            throw new InvalidArgumentException("Service class does not exist: {$serviceClass}");
        }

        if (!is_subclass_of($serviceClass, OAuthServiceInterface::class)) {
            throw new InvalidArgumentException("Service class must implement OAuthServiceInterface");
        }

        self::$providerServices[$provider] = $serviceClass;

        // Clear the cache for this specific provider if it exists
        self::clearCache($provider);
    }

    /**
     * Clears the cache of service instances.
     *
     * This can be used to force the factory to re-instantiate services.
     *
     * @param string|null $provider Specific provider to clear, or null to clear all cached instances.
     */
    public static function clearCache(?string $provider = null): void
    {
        if ($provider) {
            unset(self::$serviceInstances[$provider]);
        } else {
            self::$serviceInstances = [];
        }
    }

    /**
     * Gets operational statistics for the factory.
     *
     * Provides insights into the number of registered providers and cached service instances.
     *
     * @return array<string, mixed> An associative array containing factory statistics.
     */
    public static function getStats(): array
    {
        return [
            'total_providers' => count(self::$providerServices),
            'cached_instances' => count(self::$serviceInstances),
            'available_providers' => array_keys(self::$providerServices),
            'cached_providers' => array_keys(self::$serviceInstances),
        ];
    }
}
