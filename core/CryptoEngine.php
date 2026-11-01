<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

/**
 * Transparent AES-256-GCM Server-Side Encryption Engine
 * Protects chat messages, previews, and personal cloud notes at rest on cPanel disk.
 */
class CryptoEngine
{
    private const KEY_FILE = DATA_DIR . '/.master_secret.php';
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    private static ?string $cachedKey = null;

    /**
     * Load or auto-generate 256-bit key stored inside a PHP-guarded file
     */
    private static function getKey(): string
    {
        if (self::$cachedKey !== null) {
            return self::$cachedKey;
        }

        if (file_exists(self::KEY_FILE)) {
            $hex = @include self::KEY_FILE;
            if (is_string($hex) && strlen($hex) === 64 && ctype_xdigit($hex)) {
                self::$cachedKey = hex2bin($hex) ?: '';
                if (strlen(self::$cachedKey) === 32) {
                    return self::$cachedKey;
                }
            }
        }

        return with_file_lock('master_key', function (): string {
            if (file_exists(self::KEY_FILE)) {
                $hex = @include self::KEY_FILE;
                if (is_string($hex) && strlen($hex) === 64 && ctype_xdigit($hex)) {
                    self::$cachedKey = hex2bin($hex) ?: '';
                    return self::$cachedKey;
                }
                throw new RuntimeException('کلید رمزنگاری آسیب دیده است؛ فایل data/.master_secret.php را از پشتیبان بازیابی کنید.');
            }
            $rawBytes = random_bytes(32);
            $hex = bin2hex($rawBytes);
            $phpContent = "<?php\n// Auto-generated 256-bit AES-GCM Master Key - DO NOT DELETE\nif (!defined('BASE_DIR')) { http_response_code(403); exit; }\nreturn '{$hex}';\n";
            $temp = self::KEY_FILE . '.tmp.' . bin2hex(random_bytes(6));
            if (@file_put_contents($temp, $phpContent, LOCK_EX) === false) {
                @unlink($temp);
                throw new RuntimeException('امکان ایجاد کلید رمزنگاری در پوشه data وجود ندارد. دسترسی نوشتن پوشه را بررسی کنید.');
            }
            @chmod($temp, 0600);
            if (!@rename($temp, self::KEY_FILE)) {
                @unlink($temp);
                throw new RuntimeException('امکان ثبت امن کلید رمزنگاری وجود ندارد.');
            }
            self::$cachedKey = $rawBytes;
            return self::$cachedKey;
        });
    }

    public static function isSupported(): bool
    {
        return function_exists('openssl_encrypt')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /**
     * Encrypt UTF-8 string using AES-256-GCM (authenticated encryption)
     */
    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return $plaintext;
        }
        if (!self::isSupported()) {
            throw new RuntimeException('افزونه OpenSSL برای رمزنگاری داده‌ها روی PHP هاست فعال نیست.');
        }
        $key = self::getKey();
        $iv = random_bytes(12); // 96-bit IV recommended by NIST for GCM
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new RuntimeException('رمزنگاری پیام با خطا مواجه شد؛ داده به صورت ناامن ذخیره نشد.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypt AES-256-GCM string (seamlessly passes through legacy plain strings)
     */
    public static function decrypt(string $stored): string
    {
        if ($stored === '' || strpos($stored, self::PREFIX) !== 0) {
            return $stored;
        }
        if (!self::isSupported()) {
            throw new RuntimeException('افزونه OpenSSL برای بازخوانی پیام‌های رمزنگاری‌شده ضروری است.');
        }
        $b64 = substr($stored, strlen(self::PREFIX));
        $raw = base64_decode($b64, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('پیام رمزنگاری‌شده ناقص یا خراب است. برای بازیابی از نسخه پشتیبان استفاده کنید.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $key = self::getKey();

        $plain = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plain === false) {
            throw new RuntimeException('بازگشایی پیام ممکن نیست: کلید رمزنگاری تغییر کرده یا داده مخدوش شده است. کلید اصلی را از نسخه پشتیبان بازیابی کنید.');
        }
        return $plain;
    }

    /**
     * Versioned authenticated media format: independently encrypted 1 MiB blocks.
     * Each block is nonce(12) || GCM tag(16) || ciphertext(plaintext length).
     * The key is HMAC-SHA256(master, "media:v1:" || media ID). AAD binds the
     * format, media ID, block number and total plaintext length. The fixed
     * block offsets allow HTTP 206 without loading an entire video into RAM.
     */
    public const MEDIA_FORMAT = 'aes-256-gcm-chunks-v1';
    public const MEDIA_BLOCK_BYTES = 1048576;
    private const MEDIA_OVERHEAD = 28;

    private static function mediaKey(string $mediaId): string
    {
        if (!preg_match('/^med_[0-9]{14}_[0-9a-f]{12}$/', $mediaId)) {
            throw new RuntimeException('شناسه فایل برای رمزنگاری معتبر نیست.');
        }
        if (!self::isSupported()) {
            throw new RuntimeException('OpenSSL برای رمزنگاری عکس‌ها و فایل‌ها روی هاست ضروری است.');
        }
        return hash_hmac('sha256', 'hamrah-media:v1:' . $mediaId, self::getKey(), true);
    }

    public static function mediaCipherSize(int $plainSize): int
    {
        return $plainSize + ($plainSize > 0 ? (int)ceil($plainSize / self::MEDIA_BLOCK_BYTES) * self::MEDIA_OVERHEAD : 0);
    }

    private static function readExactly($fp, int $length): string
    {
        $out = '';
        while (strlen($out) < $length) {
            $part = fread($fp, $length - strlen($out));
            if ($part === false || $part === '') {
                throw new RuntimeException('فایل رمزنگاری‌شده یا قطعه بارگذاری‌شده ناقص است.');
            }
            $out .= $part;
        }
        return $out;
    }

    private static function writeExactly($fp, string $data): void
    {
        $offset = 0;
        $size = strlen($data);
        while ($offset < $size) {
            $written = fwrite($fp, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new RuntimeException('نوشتن فایل رمزنگاری‌شده روی هاست ناموفق بود.');
            }
            $offset += $written;
        }
    }

    public static function encryptMediaFile(string $plainPath, string $cipherPath, string $mediaId): void
    {
        $size = @filesize($plainPath);
        if (!is_int($size) || $size < 1) {
            throw new RuntimeException('فایل اصلی برای رمزنگاری وجود ندارد یا خالی است.');
        }
        $key = self::mediaKey($mediaId);
        $in = @fopen($plainPath, 'rb');
        $out = @fopen($cipherPath, 'xb');
        if (!$in || !$out) {
            if ($in) fclose($in);
            if ($out) fclose($out);
            @unlink($cipherPath);
            throw new RuntimeException('ایجاد فایل رمزنگاری‌شده روی هاست ممکن نیست.');
        }
        @chmod($cipherPath, 0600);
        try {
            $offset = 0;
            $index = 0;
            while ($offset < $size) {
                $length = min(self::MEDIA_BLOCK_BYTES, $size - $offset);
                $plain = self::readExactly($in, $length);
                $nonce = random_bytes(12);
                $tag = '';
                $aad = self::MEDIA_FORMAT . '|' . $mediaId . '|' . $index . '|' . $size;
                $cipher = openssl_encrypt($plain, self::CIPHER, $key, OPENSSL_RAW_DATA, $nonce, $tag, $aad, 16);
                if ($cipher === false || strlen($tag) !== 16) {
                    throw new RuntimeException('رمزنگاری فایل کامل نشد؛ فایل بدون رمز روی سرور نگهداری نخواهد شد.');
                }
                self::writeExactly($out, $nonce . $tag . $cipher);
                $offset += $length;
                $index++;
            }
            if (!fflush($out)) throw new RuntimeException('ثبت قطعی فایل رمزنگاری‌شده ناموفق بود.');
            if (function_exists('fsync') && !@fsync($out)) throw new RuntimeException('همگام‌سازی فایل رمزنگاری‌شده ناموفق بود.');
        } catch (Throwable $e) {
            fclose($in);
            fclose($out);
            @unlink($cipherPath);
            throw $e;
        }
        fclose($in);
        fclose($out);
        if (@filesize($cipherPath) !== self::mediaCipherSize($size)) {
            @unlink($cipherPath);
            throw new RuntimeException('اندازه فایل رمزنگاری‌شده نادرست است.');
        }
    }

    public static function decryptMediaBlock($fp, string $mediaId, int $index, int $plainSize): string
    {
        $count = (int)ceil($plainSize / self::MEDIA_BLOCK_BYTES);
        if ($index < 0 || $index >= $count) {
            throw new RuntimeException('محدوده فایل نامعتبر است.');
        }
        $blockLen = min(self::MEDIA_BLOCK_BYTES, $plainSize - $index * self::MEDIA_BLOCK_BYTES);
        $offset = $index * (self::MEDIA_BLOCK_BYTES + self::MEDIA_OVERHEAD);
        if (fseek($fp, $offset) !== 0) throw new RuntimeException('امکان خواندن قطعه فایل وجود ندارد.');
        $record = self::readExactly($fp, self::MEDIA_OVERHEAD + $blockLen);
        $aad = self::MEDIA_FORMAT . '|' . $mediaId . '|' . $index . '|' . $plainSize;
        $plain = openssl_decrypt(substr($record, self::MEDIA_OVERHEAD), self::CIPHER,
            self::mediaKey($mediaId), OPENSSL_RAW_DATA, substr($record, 0, 12), substr($record, 12, 16), $aad);
        if ($plain === false || strlen($plain) !== $blockLen) {
            throw new RuntimeException('اصالت فایل تأیید نشد: کلید اشتباه است یا فایل دستکاری/خراب شده است.');
        }
        return $plain;
    }
}
