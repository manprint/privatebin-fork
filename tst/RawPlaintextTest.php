<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PrivateBin\Configuration;
use PrivateBin\Controller;
use PrivateBin\Data\Filesystem;
use PrivateBin\Model;
use PrivateBin\Persistence\ServerSalt;

/**
 * Tests for the decrypted variant of the raw download endpoint, used by curl.
 */
class RawPlaintextTest extends TestCase
{
    private $_key;

    private $_fragment;

    private $_conf;

    private $_model;

    protected $_path;

    public function setUp(): void
    {
        $this->_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_raw_plaintext';
        if (!is_dir($this->_path)) {
            mkdir($this->_path);
        }
        $options = parse_ini_file(CONF_SAMPLE, true);
        $options['purge']['limit'] = 0;
        $options['traffic']['limit'] = 0;
        $options['model'] = ['class' => 'Filesystem'];
        $options['model_options'] = ['dir' => $this->_path];
        Helper::confBackup();
        Helper::createIniFile(CONF, $options);
        ServerSalt::setStore(new Filesystem($options['model_options']));
        $this->_conf = new Configuration;
        $this->_model = new Model($this->_conf);
        $this->_key = random_bytes(32);
        $this->_fragment = $this->_base58encode($this->_key);
        $_SERVER['REMOTE_ADDR'] = '::1';
    }

    public function tearDown(): void
    {
        unlink(CONF);
        Helper::confRestore();
        Helper::rmDir($this->_path);
    }

    /**
     * Base58 encode a binary string the way the frontend does.
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

    /**
     * Encrypt a plaintext the way the frontend does.
     *
     * @param  array  $plaintext
     * @param  string $key
     * @param  string $password
     * @param  string $compression
     * @return array
     */
    private function _encrypt(array $plaintext, $key, $password = '', $compression = 'zlib')
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
        $adata = [$spec, 'plaintext', 0, 0];
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
            'ct' => base64_encode($ciphertext . $tag),
            'v' => 2,
        ];
    }

    /**
     * Store an encrypted document under the given name.
     *
     * @param  string $name
     * @param  array  $plaintext
     * @param  string $key
     * @param  string $password
     */
    private function _store($name, array $plaintext, $key = null, $password = '')
    {
        $key = $key ?? $this->_key;
        $data = Helper::getPastePost(['name' => $name, 'tag' => 'shell']);
        $data = array_merge($data, $this->_encrypt($plaintext, $key, $password));
        $paste = $this->_model->getPaste();
        $paste->setData($data);
        $paste->store();
        $this->assertSame($name, $paste->getId(), 'document stored under its name');
    }

    /**
     * Request the raw endpoint.
     *
     * @param  array $params
     * @return string
     */
    private function _requestRaw(array $params)
    {
        $_GET = array_merge(['raw' => '1'], $params);
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['QUERY_STRING'] = http_build_query($_GET);
        ob_start();
        new Controller;
        return ob_get_clean();
    }

    /**
     * @runInSeparateProcess
     */
    public function testKeyParameterReturnsPlaintext()
    {
        $this->_store('plain-doc', ['paste' => 'the quick brown fox']);
        $content = $this->_requestRaw(['pasteid' => 'plain-doc', 'key' => $this->_fragment]);
        $this->assertNotSame(400, http_response_code(), 'the key is accepted');
        $this->assertSame('the quick brown fox' . PHP_EOL, $content, 'plaintext is returned, not the ciphertext');
    }

    /**
     * @runInSeparateProcess
     */
    public function testUncompressedDocumentIsDecrypted()
    {
        $data = Helper::getPastePost(['name' => 'raw-doc']);
        $data = array_merge($data, $this->_encrypt(['paste' => 'no compression here'], $this->_key, '', 'none'));
        $paste = $this->_model->getPaste();
        $paste->setData($data);
        $paste->store();
        $content = $this->_requestRaw(['pasteid' => 'raw-doc', 'key' => $this->_fragment]);
        $this->assertSame('no compression here' . PHP_EOL, $content);
    }

    /**
     * @runInSeparateProcess
     */
    public function testPasswordParameterIsUsed()
    {
        $this->_store('secret-doc', ['paste' => 'protected content'], $this->_key, 'p4ssw0rd');
        $content = $this->_requestRaw([
            'pasteid' => 'secret-doc',
            'key' => $this->_fragment,
            'password' => 'p4ssw0rd',
        ]);
        $this->assertSame('protected content' . PHP_EOL, $content);

        $content = $this->_requestRaw(['pasteid' => 'secret-doc', 'key' => $this->_fragment]);
        $this->assertSame(400, http_response_code(), 'missing password is rejected');
        $this->assertStringContainsString('Could not decrypt the document', $content);
    }

    /**
     * @runInSeparateProcess
     */
    public function testWrongKeyIsRejected()
    {
        $this->_store('wrong-key-doc', ['paste' => 'payload']);
        $content = $this->_requestRaw(['pasteid' => 'wrong-key-doc', 'key' => $this->_base58encode(random_bytes(32))]);
        $this->assertSame(400, http_response_code());
        $this->assertStringContainsString('Could not decrypt the document', $content);
        $this->assertStringNotContainsString('payload', $content);
    }

    /**
     * @runInSeparateProcess
     */
    public function testSingleAttachmentIsReturnedAsFile()
    {
        $this->_store('attachment-doc', [
            'attachment' => ['data:text/plain;base64,' . base64_encode('attachment body')],
            'attachment_name' => ['notes.txt'],
        ]);
        $content = $this->_requestRaw(['pasteid' => 'attachment-doc', 'key' => $this->_fragment]);
        $this->assertSame('attachment body', $content);
    }

    /**
     * @runInSeparateProcess
     */
    public function testWithoutKeyTheDocumentStaysEncrypted()
    {
        $this->_store('encrypted-doc', ['paste' => 'still secret']);
        $content = $this->_requestRaw(['pasteid' => 'encrypted-doc']);
        $response = json_decode($content, true);
        $this->assertIsArray($response);
        $this->assertArrayHasKey('ct', $response);
        $this->assertArrayHasKey('adata', $response);
        $this->assertStringNotContainsString('still secret', $content, 'the ciphertext does not contain the plaintext');
    }
}
