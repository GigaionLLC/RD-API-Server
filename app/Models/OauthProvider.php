<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An OAuth / OIDC identity provider configuration.
 */
#[Fillable([
    'op', 'type', 'client_id', 'client_secret', 'scopes', 'groups_claim', 'issuer', 'auto_register',
    'pkce_enable', 'pkce_method', 'enabled',
])]
#[Hidden(['client_secret'])]
class OauthProvider extends Model
{
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'auto_register' => 'boolean',
            'pkce_enable' => 'boolean',
            'enabled' => 'boolean',
        ];
    }
}
