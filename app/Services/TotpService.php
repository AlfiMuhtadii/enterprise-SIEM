<?php

namespace App\Services;

/**
 * IDENTITY-SSO-MFA (scoped to TOTP): RFC 6238 time-based one-time passwords,
 * implemented dependency-free (stdlib hash_hmac only) so no new composer
 * package is needed. Compatible with any standard authenticator app (Google
 * Authenticator, Authy, 1Password, etc.) via the otpauth:// provisioning URI.
 */
class TotpService
{
    private const PERIOD_SECONDS = 30;
    private const DIGITS = 6;
    private const SECRET_BYTES = 20; // 160 bits
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public function generateSecret(): string
    {
        return $this->base32Encode(random_bytes(self::SECRET_BYTES));
    }

    public function provisioningUri(string $accountName, string $issuer, string $secret): string
    {
        $label = rawurlencode($issuer).':'.rawurlencode($accountName);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&period=%d&digits=%d&algorithm=SHA1',
            $label,
            $secret,
            rawurlencode($issuer),
            self::PERIOD_SECONDS,
            self::DIGITS,
        );
    }

    /**
     * Verifies a submitted code against the secret, tolerating clock drift by
     * checking one period before/after the current one ($window steps each
     * side of "now").
     */
    public function verify(string $secret, string $code, int $window = 1): bool
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (!ctype_digit($code) || strlen($code) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD_SECONDS);
        for ($i = -$window; $i <= $window; $i++) {
            if (hash_equals($this->generateCode($secret, $counter + $i), $code)) {
                return true;
            }
        }

        return false;
    }

    private function generateCode(string $secret, int $counter): string
    {
        $key = $this->base32Decode($secret);
        $counterBytes = pack('N2', 0, $counter); // 8-byte big-endian counter (top 4 bytes always 0 at this timescale)
        $hash = hash_hmac('sha1', $counterBytes, $key, true);
        $offset = ord($hash[19]) & 0x0F;
        $truncated =
            ((ord($hash[$offset]) & 0x7F) << 24) |
            ((ord($hash[$offset + 1]) & 0xFF) << 16) |
            ((ord($hash[$offset + 2]) & 0xFF) << 8) |
            (ord($hash[$offset + 3]) & 0xFF);

        return str_pad((string) ($truncated % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $chunk = str_pad($chunk, 5, '0', STR_PAD_RIGHT);
            $output .= self::BASE32_ALPHABET[bindec($chunk)];
        }

        return $output;
    }

    private function base32Decode(string $data): string
    {
        $data = strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $data) ?? '');
        $bits = '';
        foreach (str_split($data) as $char) {
            $pos = strpos(self::BASE32_ALPHABET, $char);
            if ($pos === false) {
                continue;
            }
            $bits .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($bits, 8) as $byteBits) {
            if (strlen($byteBits) < 8) {
                continue;
            }
            $bytes .= chr(bindec($byteBits));
        }

        return $bytes;
    }
}
