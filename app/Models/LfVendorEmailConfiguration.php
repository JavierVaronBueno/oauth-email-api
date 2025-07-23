<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;

/**
 * Model for email provider configurations.
 *
 * Represents the API configurations for email providers (Microsoft Graph and Google API),
 * including credentials, access tokens, and OAuth2.0 setup.
 *
 * @property int $uid Unique identifier for the configuration.
 * @property int $vec_vendor_id ID of the associated vendor.
 * @property int $vec_location_id ID of the associated location.
 * @property string|null $vec_user_email Configured user's email address.
 * @property string $vec_provider_api API provider (e.g., 'microsoft' or 'google').
 * @property string $vec_client_id API client ID.
 * @property string $vec_client_secret API client secret.
 * @property string $vec_tenant_id API tenant ID.
 * @property string $vec_redirect_uri Redirect URI for authentication.
 * @property string $vec_access_token API access token.
 * @property string|null $vec_refresh_token API refresh token.
 * @property int $vec_expires_in Token validity duration in seconds.
 * @property Carbon $vec_expires_at Date and time of token expiration.
 * @property Carbon|null $TS_create Creation timestamp of the record.
 * @property Carbon|null $TS_update Last update timestamp of the record.
 * @property Carbon|null $del Soft deletion timestamp.
 */
class LfVendorEmailConfiguration extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'lf_vendor_email_configuration';

    /**
     * The primary key for the model.
     *
     * @var string
     */
    protected $primaryKey = 'uid';

    /**
     * The name of the "created at" column.
     *
     * @var string
     */
    public const CREATED_AT = 'TS_create';

    /**
     * The name of the "updated at" column.
     *
     * @var string
     */
    public const UPDATED_AT = 'TS_update';

    /**
     * The name of the "deleted at" column.
     *
     * @var string
     */
    public const DELETED_AT = 'del';

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
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
     * The attributes that should be mutated to dates.
     *
     * @var array<int, string>
     */
    protected $dates = [
        'vec_expires_at',
        'TS_create',
        'TS_update',
        'del',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
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
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'vec_client_secret',
        'vec_access_token',
        'vec_refresh_token',
    ];

    /**
     * Constant for the Microsoft API provider.
     *
     * @var string
     */
    public const PROVIDER_MICROSOFT = 'microsoft';

    /**
     * Constant for the Google API provider.
     *
     * @var string
     */
    public const PROVIDER_GOOGLE = 'google';

    /**
     * Array of valid API providers.
     *
     * @var array<int, string>
     */
    public const VALID_PROVIDERS = [
        self::PROVIDER_MICROSOFT,
        self::PROVIDER_GOOGLE,
    ];

    /**
     * Checks if the access token has expired.
     *
     * @return bool True if the token has expired, false otherwise.
     */
    public function isTokenExpired(): bool
    {
        return $this->vec_expires_at && Carbon::now()->greaterThan($this->vec_expires_at);
    }

    /**
     * Checks if the token is expiring soon (within the next 5 minutes).
     *
     * @return bool True if the token is expiring soon, false otherwise.
     */
    public function isTokenExpiringSoon(): bool
    {
        return $this->vec_expires_at && Carbon::now()->addMinutes(5)->greaterThan($this->vec_expires_at);
    }

    /**
     * Checks if the token is currently valid (not expired).
     *
     * @return bool True if the token is valid, false otherwise.
     */
    public function hasValidToken(): bool
    {
        return !$this->isTokenExpired();
    }

    /**
     * Checks if the configured provider is Microsoft.
     *
     * @return bool True if the provider is Microsoft, false otherwise.
     */
    public function isMicrosoftProvider(): bool
    {
        return $this->vec_provider_api === self::PROVIDER_MICROSOFT;
    }

    /**
     * Checks if the configured provider is Google.
     *
     * @return bool True if the provider is Google, false otherwise.
     */
    public function isGoogleProvider(): bool
    {
        return $this->vec_provider_api === self::PROVIDER_GOOGLE;
    }

    /**
     * Updates the token information for the configuration.
     *
     * @param array $tokenData An associative array containing new token data (e.g., 'access_token', 'refresh_token', 'expires_in').
     * @return bool True if the update was successful, false otherwise.
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
     * Scope a query to only include configurations for a specific provider.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @param string $provider The name of the provider (e.g., 'microsoft', 'google').
     * @return Builder
     */
    public function scopeByProvider(Builder $query, string $provider)
    {
        return $query->where('vec_provider_api', $provider);
    }

    /**
     * Scope a query to only include configurations for a specific vendor.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @param int $vendorId The ID of the vendor.
     * @return Builder
     */
    public function scopeByVendor(Builder $query, int $vendorId)
    {
        return $query->where('vec_vendor_id', $vendorId);
    }

    /**
     * Scope a query to only include configurations for a specific location.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @param int $locationId The ID of the location.
     * @return Builder
     */
    public function scopeByLocation(Builder $query, int $locationId)
    {
        return $query->where('vec_location_id', $locationId);
    }

    /**
     * Scope a query to only include configurations with valid (non-expired) tokens.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @return Builder
     */
    public function scopeWithValidTokens(Builder $query)
    {
        return $query->where('vec_expires_at', '>', Carbon::now());
    }

    /**
     * Scope a query to only include configurations with expired tokens.
     *
     * @param Builder $query The Eloquent query builder instance.
     * @return Builder
     */
    public function scopeWithExpiredTokens(Builder $query)
    {
        return $query->where('vec_expires_at', '<=', Carbon::now());
    }

    /**
     * Accessor for the formatted display name of the provider.
     *
     * @return string The display name of the provider (e.g., 'Microsoft Graph', 'Google API').
     */
    public function getProviderDisplayNameAttribute(): string
    {
        return match ($this->vec_provider_api) {
            self::PROVIDER_MICROSOFT => 'Microsoft Graph',
            self::PROVIDER_GOOGLE => 'Google API',
            default => 'Unknown',
        };
    }

    /**
     * Accessor for the human-readable time remaining until token expiration.
     *
     * Returns "Expired" if the token has already expired.
     *
     * @return string|null The human-readable time until expiration, or null if expiration date is not set.
     */
    public function getTimeUntilExpirationAttribute(): ?string
    {
        if (!$this->vec_expires_at) {
            return null;
        }

        $now = Carbon::now();
        if ($now->greaterThan($this->vec_expires_at)) {
            return 'Expired';
        }

        return $now->diffForHumans($this->vec_expires_at);
    }

    /**
     * Mutator for the API provider attribute.
     *
     * Ensures that only valid provider values are set.
     *
     * @param string $value The provider API value to set.
     * @throws \InvalidArgumentException If an invalid provider value is provided.
     */
    public function setVecProviderApiAttribute(string $value): void
    {
        if (!in_array($value, self::VALID_PROVIDERS)) {
            throw new \InvalidArgumentException("Invalid provider: {$value}");
        }
        $this->attributes['vec_provider_api'] = $value;
    }

    /**
     * The "booting" method of the model.
     *
     * Sets `TS_create` on creation and `TS_update` on update if not already set.
     *
     * @return void
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
