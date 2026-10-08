<?php

namespace Tests\Feature\Tachograph;

use App\Services\Mapon\MaponClient;
use App\Services\Mapon\MaponException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class MaponClientTest extends TestCase
{
    private const KEY = 'test-secret-key-abc123';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.mapon.key' => self::KEY,
            'services.mapon.base_url' => 'https://mapon.test/api/v1/',
            'services.mapon.auth_mode' => 'header',
            'services.mapon.auth_header' => 'key',
        ]);

        Sleep::fake();
    }

    private function client(): MaponClient
    {
        return $this->app->make(MaponClient::class);
    }

    public function test_it_returns_drivers_and_sends_key_in_header(): void
    {
        Http::fake([
            'mapon.test/api/v1/driver/list.json*' => Http::response(['data' => ['drivers' => [['id' => 1, 'name' => 'A']]]]),
        ]);

        $drivers = $this->client()->getDrivers();

        $this->assertSame([['id' => 1, 'name' => 'A']], $drivers);
        Http::assertSent(fn (Request $r) => $r->hasHeader('key', self::KEY)
            && ! str_contains($r->url(), self::KEY));
    }

    public function test_query_auth_mode_puts_key_in_query(): void
    {
        config(['services.mapon.auth_mode' => 'query']);
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->getCompany();

        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'key='.self::KEY) && ! $r->hasHeader('key'));
    }

    public function test_daily_activities_uses_utc_times_and_array_include_params(): void
    {
        Http::fake(['*' => Http::response(['data' => [['day' => '2026-09-28 00:00:00', 'activities' => []]]])]);

        $result = $this->client()->getDriverDailyActivities(
            42,
            new \DateTimeImmutable('2026-09-28 02:00:00', new \DateTimeZone('Europe/Copenhagen')),
            new \DateTimeImmutable('2026-10-05 00:00:00', new \DateTimeZone('UTC')),
            ['card_events', 'work_place_events'],
        );

        $this->assertCount(1, $result);
        Http::assertSent(function (Request $r) {
            $url = urldecode($r->url());

            return str_contains($url, 'driver/daily_activities.json')
                && str_contains($url, 'driver=42')
                && str_contains($url, 'from=2026-09-28T00:00:00Z')
                && str_contains($url, 'till=2026-10-05T00:00:00Z')
                && str_contains($url, 'include[]=card_events')
                && str_contains($url, 'include[]=work_place_events');
        });
    }

    public function test_daily_activities_sends_no_include_by_default(): void
    {
        Http::fake(['*' => Http::response(['data' => []])]);

        $this->client()->getDriverDailyActivities(42, new \DateTimeImmutable('2026-09-28'), new \DateTimeImmutable('2026-10-05'));

        Http::assertSent(fn (Request $r) => ! str_contains(urldecode($r->url()), 'include'));
    }

    public function test_error_in_body_with_http_200_throws(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 1015, 'msg' => 'Paid add-on required']])]);

        try {
            $this->client()->getDrivingTimeExtended(900001);
            $this->fail('Expected MaponException');
        } catch (MaponException $e) {
            $this->assertSame(1015, $e->getCode());
            $this->assertFalse($e->isRetryable());
        }

        Http::assertSentCount(1);
    }

    public function test_request_limit_error_is_retried_then_succeeds(): void
    {
        Http::fakeSequence()
            ->push(['error' => ['code' => 1011, 'msg' => 'Request limit reached']])
            ->push(['error' => ['code' => 1011, 'msg' => 'Request limit reached']])
            ->push(['data' => ['drivers' => []]]);

        $this->assertSame([], $this->client()->getDrivers());
        Http::assertSentCount(3);
        Sleep::assertSleptTimes(2);
    }

    public function test_server_errors_are_retried_and_eventually_thrown(): void
    {
        Http::fake(['*' => Http::response('Bad gateway', 502)]);

        $this->expectException(MaponException::class);
        $this->expectExceptionMessage('HTTP 502');

        try {
            $this->client()->getDrivers();
        } finally {
            Http::assertSentCount(4); // 1 + 3 retries
        }
    }

    public function test_connection_errors_never_leak_the_api_key(): void
    {
        foreach (['header', 'query'] as $mode) {
            config(['services.mapon.auth_mode' => $mode]);

            Http::fake(function (Request $request) {
                throw new ConnectionException('cURL error 28: Operation timed out for '.$request->url());
            });

            try {
                $this->client()->getDrivers();
                $this->fail('Expected MaponException');
            } catch (MaponException $e) {
                $this->assertStringNotContainsString(self::KEY, $e->getMessage());
                $this->assertStringNotContainsString('mapon.test', $e->getMessage());
                $this->assertNull($e->getPrevious());
                $this->assertStringNotContainsString(self::KEY, (string) $e);
            }
        }
    }

    public function test_error_message_echoing_the_key_is_redacted(): void
    {
        Http::fake(['*' => Http::response(['error' => ['code' => 1005, 'msg' => 'Key '.self::KEY.' not found']])]);

        try {
            $this->client()->getCompany();
            $this->fail('Expected MaponException');
        } catch (MaponException $e) {
            $this->assertSame(1005, $e->getCode());
            $this->assertStringNotContainsString(self::KEY, $e->getMessage());
        }
    }

    public function test_missing_key_fails_without_any_request(): void
    {
        config(['services.mapon.key' => '']);
        Http::fake();

        $this->expectException(MaponException::class);

        try {
            $this->client()->getDrivers();
        } finally {
            Http::assertNothingSent();
        }
    }

    public function test_log_redaction_strips_key_and_query_values(): void
    {
        $redacted = \App\Logging\RedactSecrets::redact('GET https://mapon.com/api/v1/x.json?key=other&driver=1 '.self::KEY);

        $this->assertStringNotContainsString(self::KEY, $redacted);
        $this->assertStringNotContainsString('key=other', $redacted);
        $this->assertStringContainsString('driver=1', $redacted);
    }
}
