<?php

namespace App\Application\Http;

final class Request
{
    public function __construct(
        private readonly string $method,
        private readonly string $uri,
        private readonly array $headers,
        private readonly array $queryParams,
        private readonly array $parsedBody,
        private readonly string $rawBody,
        private array $attributes = []
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = function_exists('getallheaders') ? getallheaders() : [];
        $rawBody = file_get_contents('php://input') ?: '';
        $decoded = json_decode($rawBody, true);

        return new self(
            method: strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            uri: strtok($_SERVER['REQUEST_URI'] ?? '/', '?') ?: '/',
            headers: array_change_key_case($headers, CASE_LOWER),
            queryParams: $_GET ?? [],
            parsedBody: is_array($decoded) ? $decoded : ($_POST ?? []),
            rawBody: $rawBody
        );
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $body
     */
    public static function fromTest(string $method, string $path, ?array $body = null, array $headers = []): self
    {
        $normalized = [];
        foreach ($headers as $key => $value) {
            $normalized[strtolower((string) $key)] = (string) $value;
        }

        $rawBody = $body !== null
            ? (json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}')
            : '';

        $uri = $path;
        $queryParams = [];
        $questionPos = strpos($path, '?');
        if ($questionPos !== false) {
            $uri = substr($path, 0, $questionPos) ?: '/';
            $queryString = substr($path, $questionPos + 1);
            if ($queryString !== '') {
                parse_str($queryString, $queryParams);
            }
        }

        return new self(
            method: strtoupper($method),
            uri: $uri,
            headers: $normalized,
            queryParams: $queryParams,
            parsedBody: $body ?? [],
            rawBody: $rawBody
        );
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function getUri(): string
    {
        return $this->uri;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getParsedBody(): array
    {
        return $this->parsedBody;
    }

    public function getRawBody(): string
    {
        return $this->rawBody;
    }

    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }

    public function withAttribute(string $key, mixed $value): self
    {
        $clone = clone $this;
        $clone->attributes[$key] = $value;

        return $clone;
    }
}
