<?php

require_once __DIR__ . '/Env.php';
require_once __DIR__ . '/App.php';
require_once __DIR__ . '/Database.php';

class Storage
{
    public static function url(?string $path): string
    {
        $path = trim((string) $path);
        if ($path === '') {
            return '';
        }

        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        if (str_starts_with($path, 'uploads/')) {
            $base = App::baseUrl();
            return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
        }

        $supabase = rtrim(Env::get('SUPABASE_URL'), '/');
        $bucket = self::bucket();
        if ($supabase === '' || $bucket === '') {
            $base = App::baseUrl();
            return ($base === '' ? '' : $base) . '/' . ltrim($path, '/');
        }

        $encoded = implode('/', array_map('rawurlencode', explode('/', $path)));
        return $supabase . '/storage/v1/object/public/' . rawurlencode($bucket) . '/' . $encoded;
    }

    public static function uploadFile(string $tmpPath, string $objectPath, string $mime): string
    {
        if (!is_readable($tmpPath)) {
            throw new RuntimeException('No se pudo leer el archivo temporal.');
        }

        if (Env::get('SUPABASE_URL') === '' || self::bucket() === '') {
            throw new RuntimeException('Faltan SUPABASE_URL o SUPABASE_STORAGE_BUCKET.');
        }

        $body = file_get_contents($tmpPath);
        if ($body === false) {
            throw new RuntimeException('No se pudo leer el archivo temporal.');
        }

        $objectPath = ltrim(str_replace('\\', '/', $objectPath), '/');
        $url = self::storageUrl('object/' . rawurlencode(self::bucket()) . '/' . self::encodePath($objectPath));
        $response = self::request('POST', $url, $body, [
            'Content-Type: ' . $mime,
            'x-upsert: true',
        ]);

        if ($response['code'] >= 200 && $response['code'] < 300) {
            return $objectPath;
        }

        if (self::isMissingBucket($response)) {
            self::createBucket();
            $response = self::request('POST', $url, $body, [
                'Content-Type: ' . $mime,
                'x-upsert: true',
            ]);
            if ($response['code'] >= 200 && $response['code'] < 300) {
                return $objectPath;
            }
        }

        if (self::isAccessDenied($response)) {
            try {
                self::ensurePublicAccess();
            } catch (Throwable $e) {
                error_log('Storage::ensurePublicAccess — ' . $e->getMessage());
            }
            $response = self::request('POST', $url, $body, [
                'Content-Type: ' . $mime,
                'x-upsert: true',
            ]);
            if ($response['code'] >= 200 && $response['code'] < 300) {
                return $objectPath;
            }
        }

        error_log('Storage::uploadFile — HTTP ' . $response['code'] . ' ' . $response['body']);
        throw new RuntimeException(
            'No se pudo guardar la fotografía en Storage (' . self::bucket() . ', HTTP ' . $response['code'] . '). '
            . 'Cree el bucket público en Supabase o configure SUPABASE_SERVICE_ROLE_KEY.'
        );
    }

    private static function isAccessDenied(array $response): bool
    {
        $body = strtolower($response['body']);
        return $response['code'] === 403
            || str_contains($body, 'unauthorized')
            || str_contains($body, 'accessdenied')
            || str_contains($body, 'row-level security');
    }

    private static function ensurePublicAccess(): void
    {
        $bucket = self::bucket();
        $db = Database::getConnection();

        $stmt = $db->prepare(
            'INSERT INTO storage.buckets (id, name, public, file_size_limit)
             VALUES (:id, :name, true, 10485760)
             ON CONFLICT (id) DO UPDATE SET public = true, file_size_limit = 10485760'
        );
        $stmt->execute(['id' => $bucket, 'name' => $bucket]);

        $policies = [
            $bucket . '_select' => 'CREATE POLICY %s ON storage.objects FOR SELECT TO public USING (bucket_id = %s)',
            $bucket . '_insert' => 'CREATE POLICY %s ON storage.objects FOR INSERT TO public WITH CHECK (bucket_id = %s)',
            $bucket . '_update' => 'CREATE POLICY %s ON storage.objects FOR UPDATE TO public USING (bucket_id = %s) WITH CHECK (bucket_id = %s)',
        ];

        foreach ($policies as $name => $template) {
            $check = $db->prepare(
                "SELECT 1 FROM pg_policies WHERE schemaname = 'storage' AND tablename = 'objects' AND policyname = :n LIMIT 1"
            );
            $check->execute(['n' => $name]);
            if ($check->fetchColumn()) {
                continue;
            }
            $quoted = '"' . str_replace('"', '""', $name) . '"';
            $sql = sprintf($template, $quoted, $db->quote($bucket), $db->quote($bucket));
            $db->exec($sql);
        }
    }

    private static function isMissingBucket(array $response): bool
    {
        $body = strtolower($response['body']);
        return $response['code'] === 404
            || str_contains($body, 'not found')
            || str_contains($body, 'bucket not found')
            || str_contains($body, 'invalidbucket');
    }

    private static function createBucket(): void
    {
        $bucket = self::bucket();
        $created = self::request('POST', self::storageUrl('bucket'), json_encode([
            'id'            => $bucket,
            'name'          => $bucket,
            'public'        => true,
            'fileSizeLimit' => 10485760,
        ], JSON_UNESCAPED_UNICODE), [
            'Content-Type: application/json',
        ]);

        if (($created['code'] >= 200 && $created['code'] < 300) || $created['code'] === 409) {
            return;
        }

        error_log('Storage::createBucket — HTTP ' . $created['code'] . ' ' . $created['body']);
        throw new RuntimeException(
            'No se encontró el bucket de Storage "' . $bucket . '". Créelo público en Supabase (Storage) o configure SUPABASE_SERVICE_ROLE_KEY.'
        );
    }

    private static function bucket(): string
    {
        return trim(Env::get('SUPABASE_STORAGE_BUCKET', 'casa_marques'));
    }

    private static function encodePath(string $path): string
    {
        return implode('/', array_map('rawurlencode', explode('/', $path)));
    }

    private static function storageUrl(string $path): string
    {
        return rtrim(Env::get('SUPABASE_URL'), '/') . '/storage/v1/' . ltrim($path, '/');
    }

    private static function apiKey(): string
    {
        $service = Env::get('SUPABASE_SERVICE_ROLE_KEY');
        if ($service !== '') {
            return $service;
        }
        return Env::get('SUPABASE_ANON_KEY');
    }

    /**
     * @return array{code:int,body:string}
     */
    private static function request(string $method, string $url, ?string $body = null, array $extraHeaders = []): array
    {
        $key = self::apiKey();
        $anon = Env::get('SUPABASE_ANON_KEY') ?: $key;
        $headers = array_merge([
            'Authorization: Bearer ' . $key,
            'apikey: ' . $anon,
        ], $extraHeaders);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $opts = [
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADER         => false,
                CURLOPT_TIMEOUT        => 30,
            ];
            if ($body !== null) {
                $opts[CURLOPT_POSTFIELDS] = $body;
            }
            curl_setopt_array($ch, $opts);
            $response = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($response === false) {
                $err = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('No se pudo conectar con Storage: ' . $err);
            }
            curl_close($ch);
            return ['code' => $code, 'body' => (string) $response];
        }

        $headerStr = implode("\r\n", $headers);
        $context = stream_context_create([
            'http' => [
                'method'        => $method,
                'header'        => $headerStr,
                'content'       => $body ?? '',
                'ignore_errors' => true,
                'timeout'       => 30,
            ],
        ]);
        $response = @file_get_contents($url, false, $context);
        $code = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
            $code = (int) $m[1];
        }
        if ($response === false) {
            throw new RuntimeException('No se pudo conectar con Storage.');
        }
        return ['code' => $code, 'body' => (string) $response];
    }
}
