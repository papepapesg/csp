<?php

namespace App\Foundation\Auth;

/**
 * Local RS256 verification of a Keycloak OIDC access token (FOUNDATION_AUTH §
 * "Modules verify JWTs locally; they must not call Keycloak on every request").
 *
 * Self-contained — no JWT library: splits the compact JWS, verifies the RS256
 * signature against the realm public key with openssl, and checks exp/nbf/iss/aud
 * with a small leeway. Returns the claim set on success, null on any failure.
 * (JWKS-by-kid rotation can layer on later; the realm public key covers the seam.)
 */
class KeycloakJwt
{
    /**
     * @param  array<string,mixed>  $cfg  sophix.auth.keycloak
     * @return array<string,mixed>|null  verified claims, or null if invalid
     */
    public static function verify(string $jwt, array $cfg): ?array
    {
        $parts = explode('.', $jwt);
        if (count($parts) !== 3) {
            return null;
        }
        [$h64, $p64, $s64] = $parts;

        $header = json_decode(self::b64url($h64) ?? '', true);
        $payload = json_decode(self::b64url($p64) ?? '', true);
        $signature = self::b64url($s64);
        if (! is_array($header) || ! is_array($payload) || $signature === null) {
            return null;
        }
        if (($header['alg'] ?? null) !== 'RS256') {
            return null; // only RS256 is accepted (no alg-confusion / "none")
        }

        $pem = self::pem((string) ($cfg['realm_public_key'] ?? ''));
        if ($pem === null) {
            return null;
        }
        if (openssl_verify($h64.'.'.$p64, $signature, $pem, OPENSSL_ALGO_SHA256) !== 1) {
            return null;
        }

        $now = time();
        $leeway = (int) ($cfg['leeway'] ?? 30);
        if (isset($payload['exp']) && $now > ((int) $payload['exp'] + $leeway)) {
            return null;
        }
        if (isset($payload['nbf']) && $now < ((int) $payload['nbf'] - $leeway)) {
            return null;
        }
        if (! empty($cfg['issuer']) && ($payload['iss'] ?? null) !== $cfg['issuer']) {
            return null;
        }
        if (! empty($cfg['audience'])) {
            $aud = $payload['aud'] ?? null;
            $auds = is_array($aud) ? $aud : [$aud];
            if (! in_array($cfg['audience'], $auds, true)) {
                return null;
            }
        }

        return $payload;
    }

    /** base64url-decode; null on malformed input. */
    private static function b64url(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    /** Accept a full PEM, or wrap a bare base64 DER body into a PUBLIC KEY PEM. */
    private static function pem(string $key): ?string
    {
        if ($key === '') {
            return null;
        }
        if (str_contains($key, 'BEGIN PUBLIC KEY')) {
            return $key;
        }

        return "-----BEGIN PUBLIC KEY-----\n".chunk_split($key, 64, "\n").'-----END PUBLIC KEY-----';
    }
}
