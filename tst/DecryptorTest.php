<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PrivateBin\Decryptor;

class DecryptorTest extends TestCase
{
    /**
     * Build a document the same way the frontend does.
     *
     * @param  array  $plaintext
     * @param  string $key
     * @param  string $password
     * @param  string $compression
     * @param  bool   $comment
     * @return array
     */
    private function _encrypt(array $plaintext, $key, $password = '', $compression = 'zlib', $comment = false)
    {
        $spec = [
            base64_encode(random_bytes(16)),
            base64_encode(random_bytes(8)),
            100000,
            256,
            128,
            'aes',
            'gcm',
            $compression,
        ];
        if ($comment) {
            $adata = array_merge($spec, ['5b65a01b43987bc2', '5b65a01b43987bc2']);
        } else {
            $adata = [$spec, 'plaintext', 0, 0];
        }
        $aad = json_encode($adata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $derivedKey = hash_pbkdf2(
            'sha256',
            $key . $password,
            base64_decode($spec[1]),
            $spec[2],
            32,
            true
        );
        $message = json_encode($plaintext);
        if ($compression === 'zlib') {
            $message = gzdeflate($message);
        }
        $tag = '';
        $ciphertext = openssl_encrypt(
            $message,
            'aes-256-gcm',
            $derivedKey,
            OPENSSL_RAW_DATA,
            base64_decode($spec[0]),
            $tag,
            $aad,
            16
        );
        return [
            'adata' => $adata,
            'v'     => 2,
            'ct'    => base64_encode($ciphertext . $tag),
        ];
    }

    public function testDecryptsCompressedPaste()
    {
        $data = $this->_encrypt(['paste' => 'hello world'], 'test-key');
        $result = Decryptor::decryptToArray($data, 'test-key');
        $this->assertSame('hello world', $result['paste']);
    }

    public function testDecryptsUncompressedPaste()
    {
        $data = $this->_encrypt(['paste' => 'plain'], 'test-key', '', 'none');
        $result = Decryptor::decryptToArray($data, 'test-key');
        $this->assertSame('plain', $result['paste']);
    }

    public function testDecryptsWithPassword()
    {
        $data = $this->_encrypt(['paste' => 'secret'], 'test-key', 'password');
        $result = Decryptor::decryptToArray($data, 'test-key', 'password');
        $this->assertSame('secret', $result['paste']);
    }

    public function testDecryptsCommentWithBoundIds()
    {
        $data = $this->_encrypt(
            ['comment' => 'a comment', 'nickname' => 'nick'],
            'test-key',
            '',
            'zlib',
            true
        );
        $result = Decryptor::decryptToArray($data, 'test-key');
        $this->assertSame('a comment', $result['comment']);
        $this->assertSame('nick', $result['nickname']);
    }

    public function testRejectsWrongKey()
    {
        $data = $this->_encrypt(['paste' => 'hello'], 'test-key');
        $this->expectException(Exception::class);
        Decryptor::decryptToArray($data, 'wrong-key');
    }

    public function testRejectsWrongPassword()
    {
        $data = $this->_encrypt(['paste' => 'hello'], 'test-key', 'password');
        $this->expectException(Exception::class);
        Decryptor::decryptToArray($data, 'test-key', 'wrong-password');
    }

    public function testRejectsCorruptDocument()
    {
        $data = $this->_encrypt(['paste' => 'hello'], 'test-key');
        $data['ct'] = base64_encode('corrupt data');
        $this->expectException(Exception::class);
        Decryptor::decryptToArray($data, 'test-key');
    }

    public function testRejectsUnsupportedFormat()
    {
        $this->expectException(Exception::class);
        Decryptor::decryptToArray(['ct' => 'abc'], 'test-key');
    }

    /**
     * Base58 encode a binary string, as the frontend does for the URL fragment.
     *
     * @param  string $input
     * @return string
     */
    private function _base58encode($input)
    {
        $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
        $bytes = array_values(unpack('C*', $input));
        $output = '';
        while (!empty($bytes)) {
            $carry = 0;
            $quotient = [];
            foreach ($bytes as $byte) {
                $carry = $carry * 256 + $byte;
                $value = intdiv($carry, 58);
                $carry %= 58;
                if (!empty($quotient) || $value !== 0) {
                    $quotient[] = $value;
                }
            }
            $output = $alphabet[$carry] . $output;
            $bytes = $quotient;
        }
        $leading = strlen($input) - strlen(ltrim($input, "\0"));
        return str_repeat('1', $leading) . $output;
    }

    public function testBase58DecodeRestoresTheKey()
    {
        $key = random_bytes(32);
        $this->assertSame($key, Decryptor::base58decode($this->_base58encode($key)));
    }

    public function testFragmentKeyIsDecodedAndPadded()
    {
        // a short key is padded with NULL bytes on the left, like the frontend
        $key = "\x01\x02\x03";
        $decoded = Decryptor::decodeFragmentKey($this->_base58encode($key));
        $this->assertSame(32, strlen($decoded));
        $this->assertSame($key, substr($decoded, -3));
        $this->assertSame(str_repeat("\0", 29), substr($decoded, 0, 29));
    }

    public function testDecryptsWithKeyFromTheUrlFragment()
    {
        $key = random_bytes(32);
        $data = $this->_encrypt(['paste' => 'from the link'], $key);
        $result = Decryptor::decryptFragmentToArray($data, $this->_base58encode($key));
        $this->assertSame('from the link', $result['paste']);
    }

    public function testFragmentDecryptionFallsBackToAPlainKey()
    {
        $data = $this->_encrypt(['paste' => 'legacy key'], 'test-key');
        $result = Decryptor::decryptFragmentToArray($data, 'test-key');
        $this->assertSame('legacy key', $result['paste']);
    }

    public function testRejectsFragmentWithInvalidCharacters()
    {
        $this->expectException(Exception::class);
        Decryptor::base58decode('not base58 0OIl');
    }

    public function testRejectsTooLongFragmentKey()
    {
        $this->expectException(Exception::class);
        Decryptor::decodeFragmentKey($this->_base58encode(random_bytes(40)));
    }
}
