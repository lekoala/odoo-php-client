<?php

namespace LeKoala\OdooClient\Http;

final readonly class HttpResponse
{
    /**
     * @param array<string, array<int, string>> $headers
     */
    public function __construct(
        public int $statusCode,
        public array $headers,
        public string $body,
        public string $url,
    ) {
    }

    public function getHeaderLine(string $name): string
    {
        foreach ($this->headers as $headerName => $values) {
            if (strcasecmp($headerName, $name) === 0) {
                return implode(', ', $values);
            }
        }

        return '';
    }
}
