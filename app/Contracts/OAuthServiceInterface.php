<?php

namespace App\Contracts;

use App\Models\LfVendorEmailConfiguration;

/**
 * Interface para servicios de OAuth2.0
 *
 * Define el contrato común para todos los servicios de autenticación OAuth2.0
 * con proveedores de correo electrónico (Microsoft Graph y Google API).
 *
 * Esta interfaz asegura que todos los servicios implementen las mismas
 * operaciones básicas de OAuth2.0 y envío de correos electrónicos.
 */
interface OAuthServiceInterface
{
    /**
     * Obtiene la URL de autenticación para el proveedor de API.
     *
     * @param int $uid Identificador único del proveedor de configuración.
     * @return string URL de autorización OAuth2.0
     * @throws \App\Exceptions\OAuthException Si la configuración no es válida
     */
    public function getAuthUrl(int $uid): string;

    /**
     * Maneja el callback de la autenticación y obtiene el token de acceso.
     *
     * @param string $code Código recibido tras la autenticación.
     * @param string|null $state Estado opcional para verificar la autenticación.
     * @return array Los datos del token (access_token, refresh_token, expires_in, etc.)
     * @throws \App\Exceptions\OAuthException Si el callback falla
     */
    public function handleCallback(string $code, ?string $state = null): array;

    /**
     * Almacena el token de acceso en la base de datos.
     *
     * @param LfVendorEmailConfiguration $config Configuración del proveedor.
     * @param array $tokenData Datos del token (access_token, refresh_token, etc).
     * @return LfVendorEmailConfiguration La configuración actualizada
     * @throws \App\Exceptions\OAuthException Si no se puede almacenar el token
     */
    public function storeToken(LfVendorEmailConfiguration $config, array $tokenData): LfVendorEmailConfiguration;

    /**
     * Obtiene un token válido, si no está caducado.
     *
     * @param LfVendorEmailConfiguration $config Configuración del proveedor.
     * @param string|null $email Correo opcional para obtener un token asociado a un usuario.
     * @return LfVendorEmailConfiguration El token válido
     * @throws \App\Exceptions\OAuthException Si no hay token válido disponible
     */
    public function getValidToken(LfVendorEmailConfiguration $config, ?string $email = null): LfVendorEmailConfiguration;

    /**
     * Refresca un token de acceso caducado.
     *
     * @param LfVendorEmailConfiguration $config Configuración con token a refrescar.
     * @return LfVendorEmailConfiguration Configuración con token actualizado
     * @throws \App\Exceptions\OAuthException Si no se puede refrescar el token
     */
    public function refreshToken(LfVendorEmailConfiguration $config): LfVendorEmailConfiguration;

    /**
     * Envía un correo electrónico a través del proveedor de la API.
     *
     * @param LfVendorEmailConfiguration $config Configuración con token de acceso válido.
     * @param array $emailData Datos del correo (destinatario, asunto, cuerpo, etc).
     * @return bool true si el correo se envió exitosamente
     * @throws \App\Exceptions\EmailException Si no se puede enviar el correo
     */
    public function sendEmail(LfVendorEmailConfiguration $config, array $emailData): bool;

    /**
     * Obtiene información del usuario utilizando el token de acceso.
     *
     * @param string $accessToken Token de acceso.
     * @return array Información del usuario
     * @throws \App\Exceptions\OAuthException Si no se puede obtener la información del usuario
     */
    public function getUserInfo(string $accessToken): array;

    /**
     * Obtiene el nombre del proveedor de API (Microsoft o Google).
     *
     * @return string Nombre del proveedor
     */
    public function getProviderName(): string;

    /**
     * Verifica si el token es válido haciendo una petición de prueba.
     *
     * @param string $accessToken Token de acceso a verificar.
     * @return bool true si el token es válido
     */
    public function validateToken(string $accessToken): bool;

    /**
     * Revoca el token de acceso en el proveedor.
     *
     * @param LfVendorEmailConfiguration $config Configuración con token a revocar.
     * @return bool true si se revocó exitosamente
     * @throws \App\Exceptions\OAuthException Si no se puede revocar el token
     */
    public function revokeToken(LfVendorEmailConfiguration $config): bool;

    /**
     * Obtiene los scopes (permisos) disponibles para el proveedor.
     *
     * @return array Lista de scopes disponibles
     */
    public function getAvailableScopes(): array;

    /**
     * Verifica si el proveedor soporta un scope específico.
     *
     * @param string $scope Scope a verificar.
     * @return bool true si el scope es soportado
     */
    public function supportsScope(string $scope): bool;

    /**
     * Almacena una nueva configuración para el proveedor de correo electrónico.
     *
     * @param array $configData Datos de configuración (vec_vendor_id, vec_location_id, etc.).
     * @return LfVendorEmailConfiguration La configuración creada
     * @throws \App\Exceptions\OAuthException Si no se puede almacenar la configuración
     */
    public function storeConfiguration(array $configData): LfVendorEmailConfiguration;
}
