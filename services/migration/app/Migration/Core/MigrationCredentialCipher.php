<?php

namespace App\Migration\Core;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

final class MigrationCredentialCipher
{
    private const PREFIX = 'migration:v1:';

    public function encryptString(string $value): string
    {
        return self::PREFIX.$this->encrypter()->encryptString($value);
    }

    public function decryptString(string $payload): string
    {
        if (str_starts_with($payload, self::PREFIX)) {
            return $this->encrypter()->decryptString(substr($payload, strlen(self::PREFIX)));
        }

        // Backward compatibility for credentials written before the dedicated migration cipher
        // existed. Fresh saves always use the versioned migration format above.
        return Crypt::decryptString($payload);
    }

    public function fingerprint(): string
    {
        return substr(hash('sha256', $this->rawKey()), 0, 16);
    }

    private function encrypter(): Encrypter
    {
        return new Encrypter($this->rawKey(), 'AES-256-CBC');
    }

    private function rawKey(): string
    {
        // The API initializes this file on the shared private volume using the key visible to
        // the HTTP process. The worker never initializes or replaces it. Both processes read the
        // same bytes for every encrypt/decrypt operation, regardless of their PHP environment.
        $path = trim((string) (getenv('MIGRATION_CREDENTIAL_KEY_FILE') ?: '/data/migration-credential.key'));
        if (! is_file($path)) {
            if (getenv('MIGRATION_SKIP_BOOTSTRAP') === 'true') {
                throw new RuntimeException('Migration credential key file has not been initialized by the API.');
            }

            $configured = trim((string) (getenv('MIGRATION_APP_KEY') ?: ($_SERVER['MIGRATION_APP_KEY'] ?? $_ENV['MIGRATION_APP_KEY'] ?? '')));
            if ($configured === '') {
                $configured = trim((string) (getenv('APP_KEY') ?: ($_SERVER['APP_KEY'] ?? $_ENV['APP_KEY'] ?? '')));
            }
            if ($configured === '') {
                throw new RuntimeException('MIGRATION_APP_KEY is missing in the Migration API process environment.');
            }

            $temporary = $path.'.'.bin2hex(random_bytes(8)).'.tmp';
            if (file_put_contents($temporary, $configured, LOCK_EX) === false) {
                throw new RuntimeException('Cannot initialize the migration credential key file.');
            }
            chmod($temporary, 0600);
            try {
                if (! @link($temporary, $path) && ! is_file($path)) {
                    throw new RuntimeException('Cannot publish the migration credential key file.');
                }
            } finally {
                unlink($temporary);
            }
        }

        $configured = trim((string) file_get_contents($path));
        $key = $configured;
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('Migration credential key file contains invalid base64 data.');
            }
            $key = $decoded;
        }
        if (strlen($key) !== 32) {
            throw new RuntimeException('Migration credential key file must contain exactly 32 bytes for AES-256-CBC.');
        }

        return $key;
    }
}
