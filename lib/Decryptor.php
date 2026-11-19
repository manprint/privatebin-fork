<?php declare(strict_types=1);
/**
 * PrivateBin
 *
 * a zero-knowledge paste bin
 *
 * @link      https://github.com/PrivateBin/PrivateBin
 * @copyright 2012 Sébastien SAUVAGE (sebsauvage.net)
 * @license   https://www.opensource.org/licenses/zlib-license.php The zlib/libpng License
 */

namespace PrivateBin;

use Exception;

/**
 * Decryptor
 *
 * Decrypts documents created by the PrivateBin frontend, for local use on the
 * command line. The key is never sent to the server.
 */
class Decryptor
{
    /**
     * Alphabet used by the base58 encoding of the key, as used by the frontend.
     *
     * @const string
     */
    const BASE58_ALPHABET = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';

    /**
     * Length of the symmetric key, in bytes.
     *
     * @const int
     */
    const KEY_LENGTH = 32;

    /**
     * Decode a base58 encoded string, as found in the URL fragment.
     *
     * @access public
     * @static
     * @param  string $input
     * @throws Exception
     * @return string binary string
     */
    public static function base58decode($input)
    {
        if (!is_string($input) || $input === '') {
            throw new Exception('Empty key.');
        }
        $bytes = [];
        for ($i = 0, $length = strlen($input); $i < $length; ++$i) {
            $value = strpos(self::BASE58_ALPHABET, $input[$i]);
            if ($value === false) {
                throw new Exception('Key is not base58 encoded.');
            }
            $carry = $value;
            foreach ($bytes as $index => $byte) {
                $carry += $byte * 58;
                $bytes[$index] = $carry & 0xff;
                $carry >>= 8;
            }
            while ($carry > 0) {
                $bytes[] = $carry & 0xff;
                $carry >>= 8;
            }
        }
        // every leading "1" represents a leading NULL byte
        $output = str_repeat("\0", strlen($input) - strlen(ltrim($input, '1')));
        for ($i = count($bytes) - 1; $i >= 0; --$i) {
            $output .= chr($bytes[$i]);
        }
        return $output;
    }

    /**
     * Turn the key from the URL fragment into the string the frontend uses.
     *
     * The frontend base58 decodes the fragment and pads it with NULL bytes to
     * the key length.
     *
     * @access public
     * @static
     * @param  string $fragment
     * @throws Exception
     * @return string
     */
    public static function decodeFragmentKey($fragment)
    {
        $decoded = self::base58decode($fragment);
        if (strlen($decoded) > self::KEY_LENGTH) {
            throw new Exception('Key is too long.');
        }
        return str_pad($decoded, self::KEY_LENGTH, "\0", STR_PAD_LEFT);
    }

    /**
     * Decrypt a document with the key as found in the URL fragment.
     *
     * @access public
     * @static
     * @param  array  $data
     * @param  string $fragment key from the URL fragment
     * @param  string $password
     * @throws Exception
     * @return string JSON encoded plaintext
     */
    public static function decryptFragment(array $data, $fragment, $password = '')
    {
        try {
            $key = self::decodeFragmentKey($fragment);
        } catch (Exception $e) {
            // not base58 encoded, use the given key unchanged
            $key = $fragment;
        }
        return self::decrypt($data, $key, $password);
    }

    /**
     * Decrypt the data of a paste or comment.
     *
     * @access public
     * @static
     * @param  array $data parsed document, as returned by the read or raw API
     * @param  string $key key from the URL fragment
     * @param  string $password optional password
     * @throws Exception
     * @return string JSON encoded plaintext
     */
    public static function decrypt(array $data, $key, $password = '')
    {
        if (!isset($data['adata'], $data['ct']) || !is_array($data['adata']) || !is_string($data['ct'])) {
            throw new Exception('Not a valid document.');
        }
        $adata = $data['adata'];
        $spec = is_array($adata[0] ?? null) ? $adata[0] : $adata;
        if (count($spec) < 8) {
            throw new Exception('Not a supported document format.');
        }
        if ($spec[5] !== 'aes' || $spec[6] !== 'gcm') {
            throw new Exception('Only AES-GCM documents are supported.');
        }
        list($iv, $salt, $iterations, $keySize, $tagSize) = [
            base64_decode((string) $spec[0], true),
            base64_decode((string) $spec[1], true),
            (int) $spec[2],
            (int) $spec[3],
            (int) $spec[4],
        ];
        if ($iv === false || $salt === false || $iterations < 1 || !in_array($keySize, [128, 192, 256], true)) {
            throw new Exception('Not a supported document format.');
        }
        $tagLength = (int) ($tagSize / 8);
        if (!in_array($tagLength, [8, 12, 16], true)) {
            throw new Exception('Not a supported document format.');
        }

        $ciphertext = base64_decode($data['ct'], true);
        if ($ciphertext === false || strlen($ciphertext) <= $tagLength) {
            throw new Exception('Not a valid document.');
        }
        $tag = substr($ciphertext, -$tagLength);
        $ciphertext = substr($ciphertext, 0, -$tagLength);

        // the frontend appends the password to the raw key before deriving the AES key
        $passwordBytes = $key . $password;

        // WebCrypto derives the key with PBKDF2/SHA-256 and the given salt
        $derivedKey = hash_pbkdf2(
            'sha256',
            $passwordBytes,
            $salt,
            $iterations,
            (int) ($keySize / 8),
            true
        );

        // the additional authenticated data is the JSON representation of the adata
        $aad = json_encode($adata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $plaintext = openssl_decrypt(
            $ciphertext,
            'aes-' . $keySize . '-gcm',
            $derivedKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            $aad
        );
        if ($plaintext === false) {
            throw new Exception('Decryption failed. Wrong key, password or corrupted document.');
        }

        if (($spec[7] ?? 'none') === 'zlib') {
            $inflated = @gzinflate($plaintext);
            if ($inflated === false) {
                throw new Exception('Decryption failed. Could not decompress the document.');
            }
            $plaintext = $inflated;
        }

        return $plaintext;
    }

    /**
     * Decrypt a document and return its decoded JSON structure.
     *
     * @access public
     * @static
     * @param  array $data
     * @param  string $key
     * @param  string $password
     * @throws Exception
     * @return array
     */
    public static function decryptToArray(array $data, $key, $password = '')
    {
        $plaintext = self::decrypt($data, $key, $password);
        $decoded = json_decode($plaintext, true, 10, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new Exception('Decrypted document is not valid.');
        }
        return $decoded;
    }

    /**
     * Decrypt a document with the key from the URL fragment and return its
     * decoded JSON structure.
     *
     * @access public
     * @static
     * @param  array  $data
     * @param  string $fragment
     * @param  string $password
     * @throws Exception
     * @return array
     */
    public static function decryptFragmentToArray(array $data, $fragment, $password = '')
    {
        $plaintext = self::decryptFragment($data, $fragment, $password);
        $decoded = json_decode($plaintext, true, 10, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new Exception('Decrypted document is not valid.');
        }
        return $decoded;
    }
}
