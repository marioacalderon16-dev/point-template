<?php
namespace App\Services;

/** Cliente HTTP mínimo con curl: timeouts, reintentos en errores de red/429/5xx. */
final class HttpClient
{
    public function request(string $method, string $url, ?array $json = null, array $headers = [], int $retries = 1, int $timeout = 10): array
    {
        $attempt = 0;
        while (true) {
            $ch = curl_init($url);
            $h = array_merge(['Accept: application/json'], $headers);
            if ($json !== null) {
                $h[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json, JSON_UNESCAPED_UNICODE));
            }
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => strtoupper($method),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 3,
                CURLOPT_TIMEOUT => $timeout,
                CURLOPT_HTTPHEADER => $h,
            ]);
            $raw = curl_exec($ch);
            $errno = curl_errno($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $transient = $errno !== 0 || $status === 429 || $status >= 500;
            if ($transient && $attempt < $retries) {
                usleep((200 * 2 ** $attempt + random_int(0, 100)) * 1000);
                $attempt++;
                continue;
            }
            if ($errno !== 0) {
                throw new \RuntimeException("HTTP {$method} {$url} falló (curl {$errno})");
            }

            $body = json_decode((string) $raw, true);
            return ['status' => $status, 'body' => is_array($body) ? $body : null, 'raw' => (string) $raw];
        }
    }
}
