<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NetworkDestinationProfile extends Model
{
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_IP     = 'ip';

    public const RARE_THRESHOLD = 3; // fewer than this unique hosts = rare

    protected $fillable = [
        'destination_key', 'destination_type', 'contact_count', 'host_count',
        'user_count', 'is_rare', 'entropy_score', 'tld', 'metadata',
        'first_seen_at', 'last_seen_at',
    ];

    protected $casts = [
        'is_rare'        => 'boolean',
        'entropy_score'  => 'float',
        'metadata'       => 'array',
        'first_seen_at'  => 'datetime',
        'last_seen_at'   => 'datetime',
    ];

    public static function upsertFromDnsEvent(DnsEvent $event): self
    {
        $profile = static::firstOrCreate(
            ['destination_key' => $event->queried_domain],
            [
                'destination_type' => self::TYPE_DOMAIN,
                'contact_count'    => 0,
                'host_count'       => 0,
                'user_count'       => 0,
                'is_rare'          => true,
                'entropy_score'    => $event->domain_entropy,
                'tld'              => $event->tld,
                'first_seen_at'    => $event->occurred_at ?? now(),
            ]
        );

        $profile->increment('contact_count');
        $profile->update(['last_seen_at' => $event->occurred_at ?? now()]);
        $profile->update(['is_rare' => $profile->host_count < self::RARE_THRESHOLD]);

        return $profile;
    }
}
