<?php

namespace App\Support;

use Firebase\JWT\ExpiredException;
use Firebase\JWT\JWT as FirebaseJwt;
use Firebase\JWT\Key;
use Firebase\JWT\SignatureInvalidException;

/**
 * Port of generateToken() in response.helper.ts and the verify half of
 * JwtMiddleware.
 *
 * Deliberately NOT Sanctum: the tokens issued here must be interchangeable with
 * the ones the Node API issues, so a browser holding a token from before the
 * cutover keeps working. That means the same algorithm (HS256, jsonwebtoken's
 * default), the same JWT_SECRET, the same 1-day expiry, and the same claim
 * names — jsonwebtoken puts the payload keys at the top level and adds `iat`
 * and `exp`, which is what firebase/php-jwt does too.
 */
class Jwt
{
    private const ALGORITHM = 'HS256';

    /** jsonwebtoken's `expiresIn: '1d'`. */
    private const TTL_SECONDS = 86400;

    /**
     * @param  array<string, mixed>  $userDetails  becomes the top-level claims
     */
    public static function generate(array $userDetails): string
    {
        $issuedAt = time();

        $payload = $userDetails + [
            'iat' => $issuedAt,
            'exp' => $issuedAt + self::TTL_SECONDS,
        ];

        return FirebaseJwt::encode($payload, self::secret(), self::ALGORITHM);
    }

    /**
     * @return array<string, mixed> the decoded claims
     *
     * @throws ExpiredException token past `exp`
     * @throws SignatureInvalidException|\UnexpectedValueException
     */
    public static function verify(string $token): array
    {
        $decoded = FirebaseJwt::decode($token, new Key(self::secret(), self::ALGORITHM));

        return json_decode(json_encode($decoded), true);
    }

    private static function secret(): string
    {
        $secret = config('photocraft.jwt_secret');

        if (! is_string($secret) || $secret === '') {
            throw new \RuntimeException('JWT_SECRET is not set.');
        }

        return $secret;
    }
}
