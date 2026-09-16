<?php

namespace LeKoala\OdooClient\Http;

use LeKoala\OdooClient\Exceptions\OdooNetworkException;

final class HttpClient
{
    private const REDIRECT_CODES = [301, 302, 303, 307, 308];
    private const SENSITIVE_HEADERS = [
        'Authorization',
        'Cookie',
        'Host',
        'Proxy-Authorization',
        'X-Odoo-Database',
    ];

    public function __construct(private readonly HttpTransportInterface $transport)
    {
    }

    public function send(HttpRequest $request): HttpResponse
    {
        $current = $request;

        for ($redirects = 0; ; $redirects++) {
            $response = $this->transport->send($current);
            $location = $response->getHeaderLine('Location');
            if (!in_array($response->statusCode, self::REDIRECT_CODES, true) || $location === '') {
                return $response;
            }
            if ($redirects >= $request->maxRedirects) {
                throw new OdooNetworkException('Too many HTTP redirects');
            }

            $target = self::resolveUrl($current->url, $location);
            $next = $current->withUrl($target);
            if (!self::sameOrigin($current->url, $target)) {
                $next = $next->withoutHeaders(self::SENSITIVE_HEADERS);
            }

            if (($response->statusCode === 303 && strtoupper($current->method) !== 'HEAD')
                || (($response->statusCode === 301 || $response->statusCode === 302)
                    && strtoupper($current->method) === 'POST')
            ) {
                $next = $next->withoutHeaders(['Content-Type', 'Content-Length', 'Transfer-Encoding']);
                $next = $next->withMethod('GET', null, $next->headers);
            }

            $current = $next;
        }
    }

    private static function sameOrigin(string $left, string $right): bool
    {
        return self::origin($left) === self::origin($right);
    }

    private static function origin(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts)) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);

        return "{$scheme}://{$host}:{$port}";
    }

    private static function resolveUrl(string $baseUrl, string $location): string
    {
        $location = trim($location);
        $locationParts = parse_url($location);
        if (is_array($locationParts) && isset($locationParts['scheme'])) {
            return $location;
        }

        $base = parse_url($baseUrl);
        if (!is_array($base) || !isset($base['scheme'], $base['host'])) {
            throw new OdooNetworkException('Cannot resolve HTTP redirect target');
        }

        $authority = $base['scheme'] . '://' . $base['host'];
        if (isset($base['port'])) {
            $authority .= ':' . $base['port'];
        }
        if (str_starts_with($location, '//')) {
            return $base['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '?')) {
            return $authority . ($base['path'] ?? '/') . $location;
        }

        $fragment = '';
        $fragmentPosition = strpos($location, '#');
        if ($fragmentPosition !== false) {
            $fragment = substr($location, $fragmentPosition);
            $location = substr($location, 0, $fragmentPosition);
        }
        $query = '';
        $queryPosition = strpos($location, '?');
        if ($queryPosition !== false) {
            $query = substr($location, $queryPosition);
            $location = substr($location, 0, $queryPosition);
        }

        $path = str_starts_with($location, '/')
            ? $location
            : dirname($base['path'] ?? '/') . '/' . $location;

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return $authority . '/' . implode('/', $segments) . $query . $fragment;
    }
}
