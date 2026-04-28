<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * One row per brand that has authorised the dashboard against Google Ads.
 *
 * The refresh_token column is encrypted at rest via the `encrypted` cast.
 * That means values are encrypted on write and decrypted on read — never
 * persisted in plaintext, never visible in dumps without the APP_KEY.
 *
 * If APP_KEY is rotated, all refresh tokens become unreadable and brands
 * must re-authorise. Don't rotate APP_KEY without coordinating that.
 */
class ConnectedAccount extends Model
{
    use HasFactory;

    protected $fillable = [
        'brand',
        'display_name',
        'customer_id',
        'login_customer_id',
        'conversion_action_resource',
        'refresh_token',
        'oauth_email',
        'connected_at',
        'last_upload_at',
        'last_upload_status',
    ];

    protected $casts = [
        'refresh_token' => 'encrypted',
        'connected_at' => 'datetime',
        'last_upload_at' => 'datetime',
    ];

    /**
     * Attribute hider — never serialise the refresh token in JSON responses.
     * Belt-and-braces; even decrypted it should never leave the server.
     */
    protected $hidden = [
        'refresh_token',
    ];

    public function isConnected(): bool
    {
        return !empty($this->refresh_token)
            && !empty($this->customer_id)
            && !empty($this->conversion_action_resource);
    }

    public function brandLabel(): Attribute
    {
        return Attribute::make(
            get: fn () => match ($this->brand) {
                'jimny_nz' => 'Jimny Rentals NZ',
                'dream_drives' => 'Dream Drives',
                'parkedfunds' => 'Parked Funds',
                'glovebox' => 'Glovebox',
                'freelegs' => 'Free Legs',
                default => $this->display_name ?? $this->brand,
            }
        );
    }
}
