<?php
if (!defined('ABSPATH')) exit;

/**
 * Lightweight AES-256-GCM helper for at-rest encryption of plugin secrets
 * (Dropbox app secret, OAuth tokens). Mirrors the encryption pattern used in
 * Aipex Case Management so credentials in wp_options are not stored in
 * plaintext.
 *
 * The key is derived from WordPress's own AUTH_KEY/AUTH_SALT constants, so it
 * is unique per site and never stored in the database itself. If those
 * constants are not unique on a given install, the result is "as secure as a
 * default WP install" rather than a regression — this is a defence-in-depth
 * measure against a wp_options table leak, not a replacement for proper
 * server/database hardening.
 */
class Aipex_Podcast_Crypto {
    const PREFIX = 'aipex_enc_v1:';

    private static function key(){
        $material = (defined('AUTH_KEY') ? AUTH_KEY : '') . (defined('AUTH_SALT') ? AUTH_SALT : '') . 'aipex-podcast-system';
        return hash('sha256', $material, true); // 32 raw bytes for AES-256
    }

    public static function encrypt($plaintext){
        if ($plaintext === '' || $plaintext === null) return '';
        if (!function_exists('openssl_encrypt')) return (string)$plaintext; // fail open rather than break the site
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt((string)$plaintext, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) return (string)$plaintext;
        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($value){
        if (!is_string($value) || $value === '') return '';
        if (strpos($value, self::PREFIX) !== 0) return $value; // not encrypted (legacy/plaintext value)
        $raw = base64_decode(substr($value, strlen(self::PREFIX)));
        if ($raw === false || strlen($raw) < 28) return '';
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }
}
