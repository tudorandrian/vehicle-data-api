<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VehicleData\Core\Auth\Counters;
use VehicleData\Core\Auth\ResolvedClient;
use VehicleData\Core\Http\Problem\Problem;

final class ThrottleClient
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var ResolvedClient $client */
        $client = $request->attributes->get('client');

        [$minute, $minuteEnd] = Counters::minuteWindow();
        $used = Counters::hit("client:{$client->id}", $minute, $minuteEnd->addDay());
        if ($used > $client->ratePerMinute) {
            $retry = max(1, $minuteEnd->getTimestamp() - now('UTC')->getTimestamp() + 1);

            return Problem::response(429, 'Too Many Requests', "Rate limit of {$client->ratePerMinute} requests per minute exceeded.", [], '/problems/rate-limited')
                ->withHeaders(['Retry-After' => (string) $retry, 'X-RateLimit-Limit' => (string) $client->ratePerMinute, 'X-RateLimit-Remaining' => '0']);
        }

        [$day, $dayEnd] = Counters::dayWindow();
        $usedToday = Counters::hit("client:{$client->id}", $day, $dayEnd->addDay());
        if ($usedToday > $client->dailyQuota) {
            $retry = max(1, $dayEnd->getTimestamp() - now('UTC')->getTimestamp() + 1);

            return Problem::response(429, 'Too Many Requests', "Daily quota of {$client->dailyQuota} requests exceeded.", [], '/problems/quota-exceeded')
                ->withHeaders(['Retry-After' => (string) $retry]);
        }

        $response = $next($request);
        $response->headers->set('X-RateLimit-Limit', (string) $client->ratePerMinute);
        $response->headers->set('X-RateLimit-Remaining', (string) max(0, $client->ratePerMinute - $used));

        return $response;
    }
}
