<?php

namespace App\Http\Controllers\Tachograph;

use App\Http\Controllers\Controller;
use App\Services\Tachograph\RefreshService;
use Illuminate\Http\JsonResponse;

/**
 * "Call URL" cron for hosts without command-line cron:
 *   https://your-domain/cron/refresh/<TACHO_CRON_TOKEN>   every 5 minutes
 * Each call works for at most TACHO_CRON_SECONDS, then the next call continues.
 * Returns only counts. Disabled (404) unless TACHO_CRON_TOKEN is set.
 */
class CronController extends Controller
{
    public function refresh(string $token, RefreshService $refresh): JsonResponse
    {
        $expected = (string) config('tachograph.cron_token');

        abort_if(strlen($expected) < 32 || ! hash_equals($expected, $token), 404);

        $budget = max(5, (int) config('tachograph.cron_seconds', 25));

        // Keep going if the caller gives up waiting; stay well inside PHP's limit.
        ignore_user_abort(true);
        @set_time_limit($budget + 90);

        $stats = $refresh->run(budgetSeconds: $budget);

        return response()->json($stats === null ? ['status' => 'busy'] : ['status' => 'ok'] + $stats);
    }
}
