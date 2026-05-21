<?php

declare(strict_types=1);

namespace App\Infrastructure\Mail;

final class SmtpMailer
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $fromAddress,
        private readonly string $fromName = 'ERP Clinic'
    ) {
    }

    public function send(string $to, string $subject, string $bodyText): void
    {
        $message = $this->buildMessage($to, $subject, $bodyText);
        $socket = @fsockopen($this->host, $this->port, $errno, $errstr, 10);
        if ($socket === false) {
            throw new \RuntimeException(sprintf('SMTP connection failed: %s', $errstr ?: (string) $errno));
        }

        try {
            $this->expect($socket, 220);
            $this->command($socket, 'EHLO localhost');
            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress . '>');
            $this->command($socket, 'RCPT TO:<' . $to . '>');
            $this->command($socket, 'DATA');
            fwrite($socket, $message . "\r\n.\r\n");
            $this->expect($socket, 250);
            $this->command($socket, 'QUIT');
        } finally {
            fclose($socket);
        }
    }

    private function buildMessage(string $to, string $subject, string $bodyText): string
    {
        $headers = [
            'From: ' . $this->formatAddress($this->fromAddress, $this->fromName),
            'To: ' . $to,
            'Subject: ' . $subject,
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
        ];

        return implode("\r\n", $headers) . "\r\n\r\n" . $bodyText;
    }

    private function formatAddress(string $email, string $name): string
    {
        return sprintf('%s <%s>', $name, $email);
    }

    private function command($socket, string $command): void
    {
        fwrite($socket, $command . "\r\n");
        $this->expect($socket, null);
    }

    private function expect($socket, ?int $code): void
    {
        $response = '';
        while (($line = fgets($socket, 515)) !== false) {
            $response .= $line;
            if (isset($line[3]) && $line[3] === ' ') {
                break;
            }
        }

        if ($code !== null && !str_starts_with($response, (string) $code)) {
            throw new \RuntimeException('Unexpected SMTP response: ' . trim($response));
        }
    }
}
