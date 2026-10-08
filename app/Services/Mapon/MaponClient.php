<?php

namespace App\Services\Mapon;

use App\Logging\RedactSecrets;
use DateTimeInterface;
use DateTimeZone;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Sleep;
use Throwable;

/**
 * Thin, read-only client for the Mapon API. Knows nothing about compliance; returns arrays only.
 */
final class MaponClient
{
    /** Raw body of the last successful response, for audit storage. */
    private ?string $lastBody = null;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly int $timeout = 60,
        private readonly string $authMode = 'header',
        private readonly string $authHeader = 'key',
        private readonly int $retries = 3,
    ) {}

    /** @return array<int, array> */
    public function getDrivers(): array
    {
        $data = $this->request('driver/list');

        return $data['drivers'] ?? $data;
    }

    /** Raw decoded JSON, max 31 days per call. */
    public function getDriverDailyActivities(
        int $driverId,
        DateTimeInterface $from,
        DateTimeInterface $till,
        array $include = [], // 'card_events' / 'work_place_events' are rejected (error 7) on this account
    ): array {
        return $this->request('driver/daily_activities', [
            'driver' => $driverId,
            'from' => self::formatTime($from),
            'till' => self::formatTime($till),
            'include' => $include,
        ]);
    }

    public function getUnits(array $include = ['drivers', 'tachograph']): array
    {
        $data = $this->request('unit/list', ['include' => $include]);

        return $data['units'] ?? $data;
    }

    public function getCompany(): array
    {
        return $this->request('company/get');
    }

    public function getDrivingTimeExtended(int $unitId): array
    {
        return $this->request('unit_data/driving_time_extended', ['unit_id' => $unitId]);
    }

    public function lastBody(): ?string
    {
        return $this->lastBody;
    }

    public static function formatTime(DateTimeInterface $time): string
    {
        return \DateTimeImmutable::createFromInterface($time)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function request(string $action, array $params = []): array
    {
        if ($this->apiKey === '') {
            throw new MaponException('Mapon API key is not configured.', MaponException::NOT_CONFIGURED, $action);
        }

        $attempt = 0;

        while (true) {
            $attempt++;

            try {
                return $this->send($action, $params);
            } catch (MaponException $e) {
                if (! $e->isRetryable() || $attempt > $this->retries) {
                    Log::channel('tachograph')->warning('Mapon request failed', [
                        'endpoint' => $action,
                        'code' => $e->getCode(),
                        'http_status' => $e->httpStatus,
                        'attempts' => $attempt,
                    ]);

                    throw $e;
                }

                Sleep::for($attempt * 2)->seconds();
            }
        }
    }

    private function send(string $action, array $params): array
    {
        $this->lastBody = null;

        if ($this->authMode === 'query') {
            $params['key'] = $this->apiKey;
        }

        try {
            $request = Http::baseUrl(rtrim($this->baseUrl, '/').'/')
                ->timeout($this->timeout)
                ->acceptJson();

            if ($this->authMode !== 'query') {
                $request = $request->withHeaders([$this->authHeader => $this->apiKey]);
            }

            // Mapon expects array params as include[]=a&include[]=b.
            $response = $request->get($action.'.json', self::buildQuery($params));
        } catch (ConnectionException) {
            throw new MaponException('Could not connect to Mapon (timeout or network error).', MaponException::CONNECTION_FAILED, $action);
        } catch (Throwable $e) {
            throw new MaponException('Unexpected error while calling Mapon ('.class_basename($e).').', MaponException::CONNECTION_FAILED, $action);
        }

        return $this->decode($action, $response);
    }

    private function decode(string $action, Response $response): array
    {
        $json = $response->json();

        // Mapon reports errors in the body, sometimes with HTTP 200.
        if (is_array($json) && isset($json['error'])) {
            $code = (int) ($json['error']['code'] ?? MaponException::HTTP_ERROR);
            $msg = RedactSecrets::redact((string) ($json['error']['msg'] ?? 'Unknown error'));

            throw new MaponException("Mapon error {$code}: {$msg}", $code, $action, $response->status());
        }

        if ($response->failed()) {
            throw new MaponException("Mapon returned HTTP {$response->status()}.", MaponException::HTTP_ERROR, $action, $response->status());
        }

        if (! is_array($json)) {
            throw new MaponException('Mapon returned a response that is not valid JSON.', MaponException::INVALID_RESPONSE, $action, $response->status());
        }

        $this->lastBody = $response->body();

        return array_key_exists('data', $json) ? (array) $json['data'] : $json;
    }

    private static function buildQuery(array $params): string
    {
        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);

        // http_build_query produces include[0]=a; Mapon wants include[]=a.
        return preg_replace('/%5B\d+%5D=/', '%5B%5D=', $query);
    }
}
