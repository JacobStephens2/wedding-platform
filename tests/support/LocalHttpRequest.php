<?php

declare(strict_types=1);

namespace Wedding\Tests\support;

use RuntimeException;

final class LocalHttpRequest
{
    public static function requireCurl(): void
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('Loopback request tests require the PHP cURL extension (php-curl).');
        }
    }

    /**
     * @param non-empty-string $method
     * @param list<string> $headers
     * @return array{status:int,body:string,headers:string}
     */
    public static function send(string $url, string $method, string $body, array $headers): array
    {
        $curl = curl_init($url);
        if ($curl === false) {
            throw new RuntimeException('Cannot initialize loopback HTTP request');
        }
        try {
            curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
                CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT_MS => 200, CURLOPT_TIMEOUT => 5,
                CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_POSTFIELDS => $body,
                CURLOPT_PROXY => '']);
            $response = curl_exec($curl);
            if (!is_string($response)) {
                return ['status' => 0, 'body' => '', 'headers' => ''];
            }
            $headerSize = curl_getinfo($curl, CURLINFO_HEADER_SIZE);
            return ['status' => curl_getinfo($curl, CURLINFO_HTTP_CODE),
                'body' => substr($response, $headerSize), 'headers' => substr($response, 0, $headerSize)];
        } finally {
            curl_close($curl);
        }
    }
}
