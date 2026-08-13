<?php

namespace App\Support;

/**
 * Port of graspcraft_backend/src/utils/CryptoUtil.ts.
 *
 * Deterministic AES-256-CBC with a FIXED key and IV, hex encoded. Every one of
 * those properties is load bearing and none of them may be "improved":
 *
 *  - Fixed IV means encrypt() is deterministic, and userLogin() relies on that:
 *    it encrypts the submitted password and looks the row up by equality
 *    (UsersService::userLogin). A random IV would make every login fail.
 *  - The key and IV are hardcoded in the Node source, NOT read from env, and
 *    they are hardcoded here for the same reason. The ALGORITHM / SECRET_KEY /
 *    IV entries in graspcraft_backend/.env are dead: nothing reads them, and
 *    their values are not even usable — they carry trailing `;` and `// 32 chars`
 *    comments that dotenv keeps as part of the string. Wiring them up here
 *    would decrypt every existing row into garbage and lock every user out.
 *    If these ever need to rotate, that is a data migration, not a config edit.
 *  - Node's crypto returns hex. PHP's openssl_encrypt returns base64 unless
 *    OPENSSL_RAW_DATA is passed, hence the bin2hex/hex2bin either side.
 *
 * Existing rows in users.name / email_id / ph_number / password were written by
 * the Node implementation, so any divergence here makes live data unreadable.
 */
class Crypto
{
    private const ALGORITHM = 'aes-256-cbc';

    /** 32 chars — matches SECRET_KEY in CryptoUtil.ts. */
    private const SECRET_KEY = '1234567890abcdef1234567890abcdef';

    /** 16 chars — matches IV in CryptoUtil.ts. */
    private const IV = '1234567890abcdef';

    public static function encrypt(string $text): string
    {
        $encrypted = openssl_encrypt(
            $text,
            self::ALGORITHM,
            self::SECRET_KEY,
            OPENSSL_RAW_DATA,
            self::IV,
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Crypto::encrypt failed: '.openssl_error_string());
        }

        return bin2hex($encrypted);
    }

    public static function decrypt(string $encrypted): string
    {
        $binary = @hex2bin($encrypted);

        if ($binary === false) {
            throw new \RuntimeException('Crypto::decrypt failed: value is not valid hex');
        }

        $decrypted = openssl_decrypt(
            $binary,
            self::ALGORITHM,
            self::SECRET_KEY,
            OPENSSL_RAW_DATA,
            self::IV,
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Crypto::decrypt failed: '.openssl_error_string());
        }

        return $decrypted;
    }

    /**
     * decrypt() that falls back to the raw value instead of throwing.
     *
     * The Sequelize getters do exactly this (`try { decrypt(raw) } catch { raw }`),
     * which is what lets a row written before encryption was introduced still
     * read back as plaintext.
     */
    public static function tryDecrypt(?string $value): ?string
    {
        if ($value === null || $value === '') {
            return $value;
        }

        try {
            return self::decrypt($value);
        } catch (\Throwable) {
            return $value;
        }
    }
}
