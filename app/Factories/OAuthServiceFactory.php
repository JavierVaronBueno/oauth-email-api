<?php

namespace App\Factories;

use App\Contracts\OAuthServiceInterface;
use App\Models\LfVendorEmailConfiguration;
use App\Services\MicrosoftOAuthService;
use App\Services\GoogleOAuthService;
use App\Exceptions\OAuthException;
use InvalidArgumentException;

/**
 * Factory para crear instancias de servicios OAuth2.0
 *
 * Implementa el patrón Factory para crear instancias de servicios OAuth2.0
 * basándose en el proveedor de API especificado en la configuración.
 *
 * Esta factory abstrae la creación de objetos complejos y permite
 * una fácil extensión para nuevos proveedores de API.
 */
class OAuthServiceFactory
{
    /**
     * Mapeo de proveedores a sus respectivas clases de servicio
     */
    private static array $providerServices = [
        LfVendorEmailConfiguration::PROVIDER_MICROSOFT => MicrosoftOAuthService::class,
        LfVendorEmailConfiguration::PROVIDER_GOOGLE => GoogleOAuthService::class,
    ];

    /**
     * Instancias de servicios en cache para evitar múltiples creaciones
     */
    private static array $serviceInstances = [];

    /**
     * Crea una instancia del servicio OAuth2.0 basándose en el proveedor
     *
     * @param string $provider Nombre del proveedor (microsoft o google)
     * @return OAuthServiceInterface Instancia del servicio OAuth2.0
     * @throws InvalidArgumentException Si el proveedor no es válido
     * @throws OAuthException Si no se puede crear el servicio
     */
    public static function create(string $provider): OAuthServiceInterface
    {
        $provider = strtolower(trim($provider));

        if (!self::isValidProvider($provider)) {
            throw new InvalidArgumentException("Proveedor OAuth2.0 no válido: {$provider}");
        }

        // Retornar instancia cacheada si existe
        if (isset(self::$serviceInstances[$provider])) {
            return self::$serviceInstances[$provider];
        }

        try {
            $serviceClass = self::$providerServices[$provider];
            $service = new $serviceClass();

            if (!$service instanceof OAuthServiceInterface) {
                throw new OAuthException("El servicio para {$provider} no implementa OAuthServiceInterface");
            }

            // Cachear la instancia para uso futuro
            self::$serviceInstances[$provider] = $service;

            return $service;
        } catch (\Exception $e) {
            throw new OAuthException("Error al crear el servicio OAuth2.0 para {$provider}: " . $e->getMessage());
        }
    }

    /**
     * Crea una instancia del servicio OAuth2.0 basándose en la configuración
     *
     * @param LfVendorEmailConfiguration $config Configuración del proveedor
     * @return OAuthServiceInterface Instancia del servicio OAuth2.0
     * @throws InvalidArgumentException Si la configuración no es válida
     * @throws OAuthException Si no se puede crear el servicio
     */
    public static function createFromConfig(LfVendorEmailConfiguration $config): OAuthServiceInterface
    {
        if (!$config->vec_provider_api) {
            throw new InvalidArgumentException("La configuración no tiene un proveedor de API especificado");
        }

        return self::create($config->vec_provider_api);
    }

    /**
     * Crea una instancia del servicio OAuth2.0 basándose en el UID de configuración
     *
     * @param int $uid Identificador único de la configuración
     * @return OAuthServiceInterface Instancia del servicio OAuth2.0
     * @throws InvalidArgumentException Si la configuración no existe
     * @throws OAuthException Si no se puede crear el servicio
     */
    public static function createFromUid(int $uid): OAuthServiceInterface
    {
        $config = LfVendorEmailConfiguration::find($uid);

        if (!$config) {
            throw new InvalidArgumentException("No se encontró la configuración con UID: {$uid}");
        }

        return self::createFromConfig($config);
    }

    /**
     * Obtiene todos los servicios OAuth2.0 disponibles
     *
     * @return array<string, OAuthServiceInterface> Mapa de proveedores a servicios
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
     * Verifica si un proveedor es válido
     *
     * @param string $provider Nombre del proveedor
     * @return bool true si el proveedor es válido
     */
    public static function isValidProvider(string $provider): bool
    {
        return array_key_exists($provider, self::$providerServices);
    }

    /**
     * Obtiene todos los proveedores disponibles
     *
     * @return array Lista de proveedores disponibles
     */
    public static function getAvailableProviders(): array
    {
        return array_keys(self::$providerServices);
    }

    /**
     * Registra un nuevo proveedor de servicio OAuth2.0
     *
     * @param string $provider Nombre del proveedor
     * @param string $serviceClass Clase del servicio
     * @throws InvalidArgumentException Si los parámetros no son válidos
     */
    public static function registerProvider(string $provider, string $serviceClass): void
    {
        if (empty($provider) || empty($serviceClass)) {
            throw new InvalidArgumentException("El proveedor y la clase del servicio no pueden estar vacíos");
        }

        if (!class_exists($serviceClass)) {
            throw new InvalidArgumentException("La clase del servicio no existe: {$serviceClass}");
        }

        if (!is_subclass_of($serviceClass, OAuthServiceInterface::class)) {
            throw new InvalidArgumentException("La clase del servicio debe implementar OAuthServiceInterface");
        }

        self::$providerServices[$provider] = $serviceClass;

        // Limpiar cache si existe
        unset(self::$serviceInstances[$provider]);
    }

    /**
     * Limpia el cache de instancias de servicios
     *
     * @param string|null $provider Proveedor específico o null para limpiar todo
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
     * Obtiene estadísticas del factory
     *
     * @return array Estadísticas del factory
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
