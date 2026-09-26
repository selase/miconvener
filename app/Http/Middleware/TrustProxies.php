<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

final class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * Every request reaches this application through Cloudflare and Cloud's
     * own load balancer, so the peer address is always theirs. Untrusted, the
     * client IP read from a request is the balancer's -- the same value for
     * everybody at once, which quietly turns every per-IP rate limit into a
     * single global one shared by the entire internet.
     *
     * Trusting all proxies is what Laravel Cloud documents, and is safe here
     * because the application is not reachable except through that edge.
     *
     * @var array<int, string>|string|null
     */
    protected $proxies = '*';

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;
}
