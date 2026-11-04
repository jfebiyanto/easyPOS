<?php

namespace App\Support\Sap;

use App\Models\CompanyDetail;
use Generator;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Client minimal SAP Business One Service Layer (login berbasis cookie B1SESSION).
 */
class SapServiceLayer
{
    private PendingRequest $http;

    private string $baseUrl;

    private bool $loggedIn = false;

    public function __construct(private readonly CompanyDetail $company)
    {
        $base = rtrim((string) $company->sap_endpoint_url, '/');
        if (Str::endsWith(strtolower($base), '/login')) {
            $base = substr($base, 0, -6);
        }
        $this->baseUrl = $base;

        self::assertSafeUrl($this->baseUrl);

        $this->http = Http::timeout(60)
            ->acceptJson()
            ->withHeaders(['Prefer' => 'odata.maxpagesize=200'])
            ->withOptions([
                'verify' => (bool) $company->sap_verify_ssl,
                'cookies' => new CookieJar,
            ]);
    }

    /**
     * Cegah SSRF: endpoint SAP tidak boleh menunjuk ke alamat internal/privat.
     */
    public static function assertSafeUrl(string $url): void
    {
        $parts = parse_url($url);
        $scheme = strtolower($parts['scheme'] ?? '');
        $host = $parts['host'] ?? '';

        abort_if(! in_array($scheme, ['http', 'https'], true) || $host === '', 422, 'URL endpoint SAP tidak valid.');

        $ips = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : (gethostbynamel($host) ?: []);

        abort_if($ips === [], 422, 'Host endpoint SAP tidak dapat di-resolve.');

        foreach ($ips as $ip) {
            $public = filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE);
            abort_if($public === false, 422, 'Endpoint SAP tidak boleh mengarah ke alamat internal/privat.');
        }
    }

    public function login(): void
    {
        $response = $this->http->post($this->baseUrl.'/Login', [
            'CompanyDB' => $this->company->sap_db_name,
            'UserName' => $this->company->sap_username,
            'Password' => $this->company->sap_password,
        ]);

        if (! $response->successful()) {
            throw new RuntimeException('Login SAP B1 gagal (HTTP '.$response->status().'): '.$this->errorMessage($response->json()));
        }

        $this->loggedIn = true;
    }

    public function logout(): void
    {
        if (! $this->loggedIn) {
            return;
        }

        try {
            $this->http->post($this->baseUrl.'/Logout');
        } catch (\Throwable) {
            // Sesi SAP akan kedaluwarsa sendiri.
        }

        $this->loggedIn = false;
    }

    /**
     * Iterasi seluruh baris sebuah resource, mengikuti paging `odata.nextLink`.
     *
     * @param  array<string, string>  $query  Parameter OData ($select, $filter, ...)
     * @return Generator<int, array<string, mixed>>
     */
    public function each(string $resource, array $query = []): Generator
    {
        $url = $this->baseUrl.'/'.$resource;
        $params = $query;

        while ($url !== null) {
            $response = $this->http->get($url, $params);

            if (! $response->successful()) {
                throw new RuntimeException("Gagal mengambil {$resource} dari SAP B1 (HTTP {$response->status()}): ".$this->errorMessage($response->json()));
            }

            $json = $response->json() ?? [];

            foreach ($json['value'] ?? [] as $row) {
                yield $row;
            }

            $next = $json['odata.nextLink'] ?? $json['@odata.nextLink'] ?? null;
            $url = $next ? $this->resolveNextLink($next) : null;
            $params = [];
        }
    }

    /**
     * Pastikan SQL Query tersimpan (entitas SQLQueries) ada dengan teks yang diharapkan.
     * Tabel yang dibaca harus diizinkan oleh b1s_sqltable.conf di server Service Layer.
     */
    public function ensureSqlQuery(string $code, string $name, string $sql): void
    {
        $url = $this->baseUrl."/SQLQueries('{$code}')";
        $existing = $this->http->get($url);

        if ($existing->status() === 404) {
            $response = $this->http->post($this->baseUrl.'/SQLQueries', ['SqlCode' => $code, 'SqlName' => $name, 'SqlText' => $sql]);
        } elseif (! $existing->successful()) {
            $response = $existing;
        } elseif (self::normalizeSql((string) $existing->json('SqlText')) !== self::normalizeSql($sql)) {
            $response = $this->http->patch($url, ['SqlText' => $sql]);
        } else {
            return;
        }

        if (! $response->successful()) {
            throw new RuntimeException("Gagal menyiapkan SQL Query {$code} di SAP B1 (HTTP {$response->status()}): ".$this->errorMessage($response->json()));
        }
    }

    /**
     * SAP menyimpan ulang teks query dengan format sendiri (mis. nama tabel diberi tanda kutip),
     * jadi pembandingan mengabaikan tanda kutip, spasi, dan huruf besar/kecil. Tanpa ini query
     * di-PATCH setiap sinkronisasi, dan eksekusi pertama sesudah PATCH bisa macet hingga timeout.
     */
    private static function normalizeSql(string $sql): string
    {
        return strtolower((string) preg_replace('/\s+/', ' ', str_replace('"', '', trim($sql))));
    }

    /**
     * Jalankan SQL Query tersimpan. Hanya halaman pertama yang diambil; `hasMore` menandakan
     * masih ada baris berikutnya sehingga pemanggil mengulang dengan parameter (cursor) baru.
     *
     * @param  array<string, int|string>  $params
     * @return array{rows: array<int, array<string, mixed>>, hasMore: bool}
     */
    public function runSqlQuery(string $code, array $params = []): array
    {
        $paramList = collect($params)->map(fn ($value, $key) => "{$key}={$value}")->implode('&');

        // Kompilasi query pertama kali di sisi SAP bisa lambat; beri batas waktu lebih longgar.
        $response = (clone $this->http)->timeout(180)->post($this->baseUrl."/SQLQueries('{$code}')/List", ['ParamList' => $paramList]);

        if (! $response->successful()) {
            throw new RuntimeException("Gagal menjalankan SQL Query {$code} di SAP B1 (HTTP {$response->status()}): ".$this->errorMessage($response->json()));
        }

        $json = $response->json() ?? [];

        return [
            'rows' => $json['value'] ?? [],
            'hasMore' => isset($json['odata.nextLink']) || isset($json['@odata.nextLink']),
        ];
    }

    private function resolveNextLink(string $next): string
    {
        if (Str::startsWith($next, ['http://', 'https://'])) {
            return $next;
        }

        return $this->baseUrl.'/'.ltrim($next, '/');
    }

    private function errorMessage(mixed $json): string
    {
        if (! is_array($json)) {
            return 'respons tidak dikenali';
        }

        return (string) ($json['error']['message']['value'] ?? $json['error']['message'] ?? 'tidak ada detail');
    }
}
