<?php

declare(strict_types=1);

namespace LeKoala\OdooClient\Http;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use LeKoala\OdooClient\Config;

final class RateLimitedRequests
{
    private const MAX_RETRY_DELAY_MS = 30_000;

    private ?float $lastRequestStartedMs = null;
    private readonly Closure $sleep;

    /** @param (callable(int): void)|null $sleep Receives a duration in microseconds. */
    public function __construct(private readonly Config $config, ?callable $sleep = null)
    {
        $this->sleep = $sleep !== null ? Closure::fromCallable($sleep) : static function (int $micros): void {
            time_nanosleep(intdiv($micros, 1_000_000), ($micros % 1_000_000) * 1_000);
        };
    }

    public function send(HttpTransportInterface $transport, HttpRequest $request): HttpResponse
    {
        $retryDelayMs = 0.0;
        $maxRetries = $this->config->getMaxRateLimitRetries();
        for ($attempt = 0; ; $attempt++) {
            $this->waitBeforeSending($retryDelayMs);
            $response = $transport->send($request);
            if ($response->statusCode !== 429 || $attempt >= $maxRetries) {
                return $response;
            }

            $retryDelayMs = $this->retryAfterMs($response) ?? (
                $this->config->getRetryBackoffBaseMs() * ($this->config->getRetryBackoffMultiplier() ** $attempt)
            );
            // Return the original response when the caller needs to defer the work.
            if (!is_finite($retryDelayMs) || $retryDelayMs > self::MAX_RETRY_DELAY_MS) {
                return $response;
            }
        }
    }

    private function waitBeforeSending(float $retryDelayMs): void
    {
        $nowMs = hrtime(true) / 1_000_000;
        $intervalDelayMs = $this->lastRequestStartedMs === null
            ? 0.0
            : $this->config->getMinRequestIntervalMs() - ($nowMs - $this->lastRequestStartedMs);
        $delayMs = max($retryDelayMs, $intervalDelayMs);

        if ($delayMs > 0) {
            ($this->sleep)((int) ceil($delayMs * 1_000));
        }
        $this->lastRequestStartedMs = hrtime(true) / 1_000_000;
    }

    private function retryAfterMs(HttpResponse $response): ?float
    {
        $value = trim($response->getHeaderLine('Retry-After'));
        if (preg_match('/^[0-9]+$/D', $value) === 1) {
            return (float) $value * 1_000;
        }

        if (str_contains($value, "\0")) {
            return null;
        }

        $utc = new DateTimeZone('UTC');
        foreach (['D, d M Y H:i:s \G\M\T', 'l, d-M-y H:i:s \G\M\T', 'D M j H:i:s Y'] as $format) {
            $date = DateTimeImmutable::createFromFormat('!' . $format, $value, $utc);
            if ($date !== false && DateTimeImmutable::getLastErrors() === false) {
                return max(0, $date->getTimestamp() - microtime(true)) * 1_000;
            }
        }

        return null;
    }
}
