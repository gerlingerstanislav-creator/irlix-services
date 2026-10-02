<?php

namespace App\Migration\Core;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class ConnectionProfileStore
{
    private const SSL_MODES = ['disable', 'allow', 'prefer', 'require', 'verify-ca', 'verify-full'];

    public function __construct(private readonly MigrationCredentialCipher $cipher)
    {
    }

    public function save(string $service, array $input): array
    {
        $this->assertKnownService($service);

        $existing = DB::table('migration_connections')->where('service', $service)->first();
        $password = trim((string) ($input['password'] ?? ''));
        if ($password === '' && ! $existing) {
            throw new InvalidArgumentException('Password is required for a new legacy connection.');
        }

        $host = trim((string) ($input['host'] ?? ''));
        $database = trim((string) ($input['database'] ?? ''));
        $username = trim((string) ($input['username'] ?? ''));
        $port = (int) ($input['port'] ?? 5432);
        $sslmode = trim((string) ($input['sslmode'] ?? 'disable'));
        $readonly = filter_var($input['readonly_acknowledged'] ?? false, FILTER_VALIDATE_BOOL);

        if ($host === '' || $database === '' || $username === '') {
            throw new InvalidArgumentException('Host, database and username are required.');
        }
        if ($port < 1 || $port > 65535) {
            throw new InvalidArgumentException('Port must be between 1 and 65535.');
        }
        if (! in_array($sslmode, self::SSL_MODES, true)) {
            throw new InvalidArgumentException('Unsupported PostgreSQL sslmode.');
        }
        if (! $readonly) {
            throw new InvalidArgumentException('Confirm that a dedicated read-only legacy DB user is being used.');
        }

        $payload = [
            'host' => $host,
            'port' => $port,
            'database' => $database,
            'username' => $username,
            'sslmode' => $sslmode,
            'readonly_acknowledged' => true,
            'verification_status' => 'not_verified',
            'verification_message' => null,
            'verified_database' => null,
            'verified_user' => null,
            'verified_at' => null,
            'updated_at' => now(),
        ];
        if ($password !== '') {
            $payload['password_encrypted'] = $this->cipher->encryptString($password);
        }

        if ($existing) {
            DB::table('migration_connections')->where('service', $service)->update($payload);
        } else {
            DB::table('migration_connections')->insert($payload + [
                'service' => $service,
                'password_encrypted' => $this->cipher->encryptString($password),
                'created_at' => now(),
            ]);
        }

        $this->purgeLegacyConnection($service);

        return $this->publicProfile($service) ?? [];
    }

    public function publicProfile(string $service): ?array
    {
        $this->assertKnownService($service);
        $row = DB::table('migration_connections')->where('service', $service)->first();
        if (! $row) {
            return null;
        }

        return [
            'service' => $service,
            'host' => $row->host,
            'port' => (int) $row->port,
            'database' => $row->database,
            'username' => $row->username,
            'password_configured' => ! empty($row->password_encrypted),
            'sslmode' => $row->sslmode,
            'readonly_acknowledged' => (bool) $row->readonly_acknowledged,
            'verification_status' => $row->verification_status,
            'verification_message' => $row->verification_message,
            'verified_database' => $row->verified_database,
            'verified_user' => $row->verified_user,
            'verified_at' => $row->verified_at,
            'updated_at' => $row->updated_at,
        ];
    }

    public function apply(string $service): bool
    {
        $this->assertKnownService($service);
        $row = DB::table('migration_connections')->where('service', $service)->first();
        if (! $row) {
            return false;
        }

        $source = config('migration.legacy.'.$service);
        if (! is_array($source)) {
            throw new RuntimeException("Unknown legacy source: {$service}");
        }

        try {
            $password = $this->cipher->decryptString((string) $row->password_encrypted);
        } catch (DecryptException $e) {
            throw new RuntimeException(
                "Cannot decrypt saved legacy {$service} credentials. The saved value uses an older Laravel Crypt payload or a different legacy APP_KEY. Re-enter and save the password once after this Migration Service version is deployed.",
                0,
                $e,
            );
        }

        $database = $source['database'] ?? [];
        $database['host'] = $row->host;
        $database['port'] = (int) $row->port;
        $database['database'] = $row->database;
        $database['username'] = $row->username;
        $database['password'] = $password;
        $database['sslmode'] = $row->sslmode;

        config([
            'migration.legacy.'.$service.'.database' => $database,
            'migration.legacy.'.$service.'.readonly_confirmed' => (bool) $row->readonly_acknowledged,
            'database.connections.'.$source['connection'] => $database,
        ]);
        $this->purgeLegacyConnection($service);

        return true;
    }

    public function markVerified(string $service, array $safety): void
    {
        DB::table('migration_connections')->where('service', $service)->update([
            'verification_status' => 'verified',
            'verification_message' => 'Read-only checks passed.',
            'verified_database' => $safety['database'] ?? null,
            'verified_user' => $safety['user'] ?? null,
            'verified_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markVerificationFailed(string $service, string $message): void
    {
        DB::table('migration_connections')->where('service', $service)->update([
            'verification_status' => 'failed',
            'verification_message' => $message,
            'verified_database' => null,
            'verified_user' => null,
            'verified_at' => null,
            'updated_at' => now(),
        ]);
    }

    public function isVerified(string $service): bool
    {
        return DB::table('migration_connections')
            ->where('service', $service)
            ->where('verification_status', 'verified')
            ->whereNotNull('verified_at')
            ->exists();
    }

    public function delete(string $service): void
    {
        $this->assertKnownService($service);
        DB::table('migration_connections')->where('service', $service)->delete();
        $this->purgeLegacyConnection($service);
    }

    private function purgeLegacyConnection(string $service): void
    {
        $name = config('migration.legacy.'.$service.'.connection');
        if (is_string($name) && $name !== '') {
            DB::purge($name);
        }
    }

    private function assertKnownService(string $service): void
    {
        if (! array_key_exists($service, config('migration.modules', []))) {
            throw new InvalidArgumentException("Unknown migration service: {$service}");
        }
    }
}
