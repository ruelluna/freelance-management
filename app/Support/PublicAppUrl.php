<?php

namespace App\Support;

class PublicAppUrl
{
    public static function canReceiveWebhooks(): bool
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return false;
        }

        return ! str_ends_with($host, '.test')
            && ! str_ends_with($host, '.local')
            && $host !== 'localhost'
            && ! str_starts_with($host, '127.');
    }
}
