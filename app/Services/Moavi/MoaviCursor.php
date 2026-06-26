<?php

namespace App\Services\Moavi;

use InvalidArgumentException;

class MoaviCursor
{
    public function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $body = $this->base64UrlEncode($json);
        $signature = hash_hmac('sha256', $body, $this->secret());

        return $body . '.' . $this->base64UrlEncode($signature);
    }

    public function decode(?string $token): ?array
    {
        if ($token === null || trim($token) === '') {
            return null;
        }

        $parts = explode('.', $token, 2);
        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Cursor invalido.');
        }

        [$body, $signature] = $parts;
        $expected = hash_hmac('sha256', $body, $this->secret());
        $given = $this->base64UrlDecode($signature);

        if (!hash_equals($expected, $given)) {
            throw new InvalidArgumentException('Cursor invalido.');
        }

        $payload = json_decode($this->base64UrlDecode($body), true);
        if (!is_array($payload)) {
            throw new InvalidArgumentException('Cursor invalido.');
        }

        return $payload;
    }

    private function secret(): string
    {
        return (string) config('app.key');
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), true);
        if ($decoded === false) {
            throw new InvalidArgumentException('Cursor invalido.');
        }

        return $decoded;
    }
}
