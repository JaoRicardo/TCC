<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Core\Configure;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class JwtService
{
    private const ALGORITHM = 'HS256';

    private static function secret(): string
    {
        return (string)Configure::read('Jwt.secret');
    }

    private static function accessTtl(): int
    {
        return (int)Configure::read('Jwt.accessTtl', 3600);
    }

    private static function refreshTtl(): int
    {
        return (int)Configure::read('Jwt.refreshTtl', 2592000);
    }

    public static function createAccessToken(int $userId): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::accessTtl(),
            'type' => 'access',
        ], self::secret(), self::ALGORITHM);
    }

    public static function createRefreshToken(int $userId): string
    {
        $now = time();

        return JWT::encode([
            'sub' => $userId,
            'iat' => $now,
            'exp' => $now + self::refreshTtl(),
            'type' => 'refresh',
            'jti' => bin2hex(random_bytes(16)),
        ], self::secret(), self::ALGORITHM);
    }

    public static function decode(string $token): ?object
    {
        try {
            return JWT::decode($token, new Key(self::secret(), self::ALGORITHM));
        } catch (\Throwable) {
            return null;
        }
    }
}
