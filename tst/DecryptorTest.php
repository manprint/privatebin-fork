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
}
