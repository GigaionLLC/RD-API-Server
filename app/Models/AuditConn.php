<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * An audit record of a connection event between peers.
 *
 * primary_auth / two_factor / conn_audit_ref are the RustDesk 1.4.9 additions (PR #15456,
 * #15407): how the incoming session authenticated and an opaque token attributing it to the
 * controlling user. All three are optional and left null for pre-1.4.9 clients / close events.
 */
#[Fillable([
    'guid', 'action', 'conn_id', 'peer_id', 'from_peer', 'from_name', 'ip', 'session_id',
    'type', 'primary_auth', 'two_factor', 'conn_audit_ref', 'uuid', 'closed_at', 'note',
])]
class AuditConn extends Model
{
    use HasFactory;

    public const ACTION_NEW = 'new';

    public const ACTION_CLOSE = 'close';

    /**
     * First-factor method the controller used against the controlled device.
     * Mirrors the RustDesk client's ConnAuditPrimaryAuth enum (PR #15456); code 0/None is
     * omitted from the wire payload, so it never reaches us.
     *
     * @var array<int, string>
     */
    public const PRIMARY_AUTH = [
        1 => 'Click-approved',
        2 => 'One-time password',
        3 => 'Permanent password',
        4 => 'Switch sides',
    ];

    /**
     * Second-factor method (ConnAuditTwoFactor, PR #15456). 0/None is omitted on the wire.
     *
     * @var array<int, string>
     */
    public const TWO_FACTOR = [
        1 => 'TOTP',
        2 => 'Trusted device',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conn_id' => 'integer',
            'type' => 'integer',
            'primary_auth' => 'integer',
            'two_factor' => 'integer',
            'closed_at' => 'datetime',
        ];
    }

    /**
     * Human-readable label for the primary auth method, or '' when unknown/absent.
     */
    public function primaryAuthLabel(): string
    {
        return self::PRIMARY_AUTH[$this->primary_auth] ?? '';
    }

    /**
     * Human-readable label for the second factor, or '' when unknown/absent.
     */
    public function twoFactorLabel(): string
    {
        return self::TWO_FACTOR[$this->two_factor] ?? '';
    }

    /**
     * Combined auth story, e.g. "Permanent password + TOTP", or '' when no auth was recorded
     * (pre-1.4.9 clients, close events, or a plain click-through that sent nothing).
     */
    public function authSummary(): string
    {
        return implode(' + ', array_filter([$this->primaryAuthLabel(), $this->twoFactorLabel()]));
    }
}
