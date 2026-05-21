<?php

namespace App\Application\Http;

class Response
{
    public function __construct(
        protected int $statusCode = 200,
        protected array $headers = [],
        protected string $body = ''
    ) {
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getBody(): string
    {
        return $this->body;
    }

    public function send(): void
    {
        http_response_code($this->statusCode);
        foreach ($this->headers as $name => $value) {
            header(sprintf('%s: %s', $name, $value));
        }
        echo $this->body;
    }
}
