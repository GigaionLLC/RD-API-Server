<?php

use App\Support\TrustedProxyConfiguration;

return ['proxies' => TrustedProxyConfiguration::parse((string) env('TRUSTED_PROXIES', ''))];
