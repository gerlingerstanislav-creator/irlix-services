<?php

namespace App\Migration\Core;

use Illuminate\Encryption\Encrypter;
use InvalidArgumentException;

final class LegacyClientsCipher
{
    public static function encrypter(string $key, string $cipher): Encrypter
    {
        if (!in_array($cipher, ['AES-256-CBC', 'AES-128-CBC', 'AES-256-GCM', 'AES-128-GCM'], true)) {
            throw new InvalidArgumentException('Неподдерживаемый алгоритм старого Clients.');
        }
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if ($raw === false || !Encrypter::supported($raw, $cipher)) {
            throw new InvalidArgumentException('APP_KEY не соответствует выбранному алгоритму. Введите полный ключ старого Clients, включая base64:.');
        }
        return new Encrypter($raw, $cipher);
    }

    public static function decryptAmount(string $payload, string $field): string
    {
        try {
            // Never unserialize legacy payloads: only scalar numeric representations are accepted.
            $plain = self::encrypter(config('migration.clients_app_key'), config('migration.clients_cipher', 'AES-256-CBC'))->decrypt($payload, false);
            if (preg_match('/^[id]:([-+0-9.eE]+);$/D', $plain, $match)) $plain = $match[1];
            elseif (preg_match('/^s:(\d+):"([-+0-9.eE]+)";$/D', $plain, $match) && strlen($match[2]) === (int) $match[1]) $plain = $match[2];
            if (!is_numeric($plain)) throw new \RuntimeException('Not numeric');
            return $plain;
        } catch (\Throwable $e) {
            throw new SourceRowConflict('RATE_DECRYPTION_FAILED', 'Не удалось расшифровать '.$field.'. Проверьте APP_KEY и алгоритм старого Clients; сумма не изменена.', ['field' => $field]);
        }
    }
}
