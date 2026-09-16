<?php

namespace LeKoala\OdooClient\Http;

final readonly class HttpRequest
{
    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $method,
        public string $url,
        public array $headers = [],
        public ?string $body = null,
        public int $maxRedirects = 0,
    ) {
    }

    public function withUrl(string $url): self
    {
        return new self($this->method, $url, $this->headers, $this->body, $this->maxRedirects);
    }

    /** @param array<string, string> $headers */
    public function withMethod(string $method, ?string $body, array $headers): self
    {
        return new self($method, $this->url, $headers, $body, $this->maxRedirects);
    }

    /** @param array<int, string> $names */
    public function withoutHeaders(array $names): self
    {
        $blocked = array_map('strtolower', $names);
        $headers = array_filter(
            $this->headers,
            static fn(string $name): bool => !in_array(strtolower($name), $blocked, true),
            ARRAY_FILTER_USE_KEY,
        );

        return new self($this->method, $this->url, $headers, $this->body, $this->maxRedirects);
    }
}
