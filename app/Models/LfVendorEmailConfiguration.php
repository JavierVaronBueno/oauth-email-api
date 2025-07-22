<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;

/**
 * Modelo para la configuración de proveedores de correo electrónico
 *
 * Representa la configuración de APIs de proveedores de correo (Microsoft Graph y Google API)
 * incluyendo credenciales, tokens de acceso y configuración de OAuth2.
 *
 * @property int $uid Identificador único de la configuración
 * @property int $vec_vendor_id ID del proveedor
 * @property int $vec_location_id ID de la ubicación asociada
 * @property string|null $vec_user_email Email del usuario configurado
 * @property string $vec_provider_api Proveedor de API (microsoft o google)
 * @property string $vec_client_id ID del cliente de la API
 * @property string $vec_client_secret Secreto del cliente de la API
 * @property string $vec_tenant_id ID del inquilino (tenant) de la API
 * @property string $vec_redirect_uri URI de redirección para autenticación
 * @property string $vec_access_token Token de acceso de la API
 * @property string|null $vec_refresh_token Token de refresco de la API
 * @property int $vec_expires_in Duración de validez del token en segundos
 * @property Carbon $vec_expires_at Fecha y hora de expiración del token
 * @property Carbon|null $TS_create Fecha de creación del registro
 * @property Carbon|null $TS_update Fecha de última actualización
 * @property Carbon|null $del Fecha de eliminación suave
 */
class LfVendorEmailConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * Nombre de la tabla en la base de datos
     */
    protected $table = 'lf_vendor_email_configuration';

    /**
     * Clave primaria personalizada
     */
    protected $primaryKey = 'uid';

    /**
     * Timestamps personalizados
     */
    public const CREATED_AT = 'TS_create';
    public const UPDATED_AT = 'TS_update';
    public const DELETED_AT = 'del';

    /**
     * Campos que pueden ser asignados masivamente
     */
    protected $fillable = [
        'vec_vendor_id',
        'vec_location_id',
        'vec_user_email',
        'vec_provider_api',
        'vec_client_id',
        'vec_client_secret',
        'vec_tenant_id',
        'vec_redirect_uri',
        'vec_access_token',
        'vec_refresh_token',
        'vec_expires_in',
        'vec_expires_at',
    ];

    /**
     * Campos que deben ser tratados como fechas
     */
    protected $dates = [
        'vec_expires_at',
        'TS_create',
        'TS_update',
        'del',
    ];

    /**
     * Campos que deben ser convertidos a tipos nativos
     */
    protected $casts = [
        'uid' => 'integer',
        'vec_vendor_id' => 'integer',
        'vec_location_id' => 'integer',
        'vec_expires_in' => 'integer',
        'vec_expires_at' => 'datetime',
        'TS_create' => 'datetime',
        'TS_update' => 'datetime',
        'del' => 'datetime',
    ];

    /**
     * Campos que deben ser ocultos en la serialización
     */
    protected $hidden = [
        'vec_client_secret',
        'vec_access_token',
        'vec_refresh_token',
    ];

    /**
     * Proveedores de API válidos
     */
    public const PROVIDER_MICROSOFT = 'microsoft';
    public const PROVIDER_GOOGLE = 'google';

    public const VALID_PROVIDERS = [
        self::PROVIDER_MICROSOFT,
        self::PROVIDER_GOOGLE,
    ];

    /**
     * Verifica si el token de acceso ha expirado
     */
    public function isTokenExpired(): bool
    {
        return $this->vec_expires_at && Carbon::now()->greaterThan($this->vec_expires_at);
    }

    /**
     * Verifica si el token está próximo a expirar (dentro de los próximos 5 minutos)
     */
    public function isTokenExpiringSoon(): bool
    {
        return $this->vec_expires_at && Carbon::now()->addMinutes(5)->greaterThan($this->vec_expires_at);
    }

    /**
     * Verifica si el token es válido (no expirado)
     */
    public function hasValidToken(): bool
    {
        return !$this->isTokenExpired();
    }

    /**
     * Verifica si es un proveedor Microsoft
     */
    public function isMicrosoftProvider(): bool
    {
        return $this->vec_provider_api === self::PROVIDER_MICROSOFT;
    }

    /**
     * Verifica si es un proveedor Google
     */
    public function isGoogleProvider(): bool
    {
        return $this->vec_provider_api === self::PROVIDER_GOOGLE;
    }

    /**
     * Actualiza la información del token
     */
    public function updateTokenInfo(array $tokenData): bool
    {
        return $this->update([
            'vec_access_token' => $tokenData['access_token'],
            'vec_refresh_token' => $tokenData['refresh_token'] ?? $this->vec_refresh_token,
            'vec_expires_in' => $tokenData['expires_in'],
            'vec_expires_at' => Carbon::now()->addSeconds($tokenData['expires_in']),
        ]);
    }

    /**
     * Scope para filtrar por proveedor
     */
    public function scopeByProvider($query, string $provider)
    {
        return $query->where('vec_provider_api', $provider);
    }

    /**
     * Scope para filtrar por vendor
     */
    public function scopeByVendor($query, int $vendorId)
    {
        return $query->where('vec_vendor_id', $vendorId);
    }

    /**
     * Scope para filtrar por location
     */
    public function scopeByLocation($query, int $locationId)
    {
        return $query->where('vec_location_id', $locationId);
    }

    /**
     * Scope para obtener configuraciones con tokens válidos
     */
    public function scopeWithValidTokens($query)
    {
        return $query->where('vec_expires_at', '>', Carbon::now());
    }

    /**
     * Scope para obtener configuraciones con tokens expirados
     */
    public function scopeWithExpiredTokens($query)
    {
        return $query->where('vec_expires_at', '<=', Carbon::now());
    }

    /**
     * Accessor para obtener el proveedor formateado
     */
    public function getProviderDisplayNameAttribute(): string
    {
        return match ($this->vec_provider_api) {
            self::PROVIDER_MICROSOFT => 'Microsoft Graph',
            self::PROVIDER_GOOGLE => 'Google API',
            default => 'Desconocido',
        };
    }

    /**
     * Accessor para obtener el tiempo restante hasta la expiración
     */
    public function getTimeUntilExpirationAttribute(): ?string
    {
        if (!$this->vec_expires_at) {
            return null;
        }

        $now = Carbon::now();
        if ($now->greaterThan($this->vec_expires_at)) {
            return 'Expirado';
        }

        return $now->diffForHumans($this->vec_expires_at);
    }

    /**
     * Mutator para el proveedor de API
     */
    public function setVecProviderApiAttribute(string $value): void
    {
        if (!in_array($value, self::VALID_PROVIDERS)) {
            throw new \InvalidArgumentException("Proveedor inválido: {$value}");
        }
        $this->attributes['vec_provider_api'] = $value;
    }

    /**
     * Boot method para eventos del modelo
     */
    protected static function boot(): void
    {
        parent::boot();

        static::creating(function ($model) {
            if (!$model->TS_create) {
                $model->TS_create = Carbon::now();
            }
        });

        static::updating(function ($model) {
            $model->TS_update = Carbon::now();
        });
    }
}
