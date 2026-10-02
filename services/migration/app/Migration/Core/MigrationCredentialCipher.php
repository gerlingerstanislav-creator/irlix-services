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
        // APP_KEY is set to the same persistent server value in the migration Compose overlay.
        // It also lets older containers with only APP_KEY decrypt existing credentials safely.
        $configured = trim((string) (getenv('MIGRATION_APP_KEY') ?: ($_SERVER['MIGRATION_APP_KEY'] ?? $_ENV['MIGRATION_APP_KEY'] ?? '')));
        if ($configured === '') {
            $configured = trim((string) (getenv('APP_KEY') ?: ($_SERVER['APP_KEY'] ?? $_ENV['APP_KEY'] ?? '')));
        }
        if ($configured === '') {
            throw new RuntimeException('MIGRATION_APP_KEY is missing in the Migration Service process environment.');
        }

        $key = $configured;
        if (str_starts_with($configured, 'base64:')) {
            $decoded = base64_decode(substr($configured, 7), true);
            if ($decoded === false) {
                throw new RuntimeException('MIGRATION_APP_KEY contains invalid base64 data.');
            }
            $key = $decoded;
        }

        if (strlen($key) !== 32) {
            throw new RuntimeException('MIGRATION_APP_KEY must contain exactly 32 bytes for AES-256-CBC.');
        }

        return $key;
    }
}
