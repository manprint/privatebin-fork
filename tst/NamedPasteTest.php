<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PrivateBin\Configuration;
use PrivateBin\Data\Filesystem;
use PrivateBin\Exception\TranslatedException;
use PrivateBin\Model;
use PrivateBin\Model\Paste;
use PrivateBin\Persistence\ServerSalt;

/**
 * Tests for user defined paste names, tags, renaming and expiry changes.
 */
class NamedPasteTest extends TestCase
{
    private $_conf;

    private $_model;

    protected $_path;

    public function setUp(): void
    {
        $this->_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_named_data';
        if (!is_dir($this->_path)) {
            mkdir($this->_path);
        }
        $options = parse_ini_file(CONF_SAMPLE, true);
        $options['purge']['limit'] = 0;
        $options['model'] = ['class' => 'Filesystem'];
        $options['model_options'] = ['dir' => $this->_path];
        Helper::confBackup();
        Helper::createIniFile(CONF, $options);
        ServerSalt::setStore(new Filesystem($options['model_options']));
        $this->_conf = new Configuration;
        $this->_model = new Model($this->_conf);
        $_SERVER['REMOTE_ADDR'] = '::1';
    }

    public function tearDown(): void
    {
        unlink(CONF);
        Helper::confRestore();
        // remove test files
        if (is_dir($this->_path)) {
            $items = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($this->_path, FilesystemIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->_path);
        }
    }

    public function testNameBecomesIdAndTagIsStored()
    {
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'my-paste', 'tag' => 'shell']);
        $paste->setData($data);
        $this->assertSame('my-paste', $paste->getId(), 'name is used as paste ID');
        $paste->store();
        $this->assertTrue($this->_model->getPaste('my-paste')->exists(), 'named paste exists');

        $stored = $this->_model->getPaste('my-paste')->getStored();
        $this->assertSame('my-paste', $stored['meta']['name']);
        $this->assertSame('shell', $stored['meta']['tag']);
        $this->assertArrayHasKey('created', $stored['meta']);
    }

    public function testUnnamedPasteKeepsChecksumId()
    {
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost();
        $paste->setData($data);
        $this->assertMatchesRegularExpression('#\A[a-f0-9]{16}\z#', $paste->getId());
        $paste->store();
        $stored = $this->_model->getPaste($paste->getId())->getStored();
        $this->assertArrayNotHasKey('name', $stored['meta']);
    }

    public function testIdValidation()
    {
        $this->assertTrue(Paste::isValidId('my-paste'), 'name with dash is valid');
        $this->assertTrue(Paste::isValidId('script_bash01'), 'name with underscore is valid');
        $this->assertTrue(Paste::isValidId('a242ab7bdfb2581a'), 'checksum ID is valid');
        $this->assertFalse(Paste::isValidId('ab'), 'too short');
        $this->assertFalse(Paste::isValidId('My-Paste'), 'uppercase is invalid');
        $this->assertFalse(Paste::isValidId('-my-paste'), 'leading dash is invalid');
        $this->assertFalse(Paste::isValidId('my paste'), 'space is invalid');
        $this->assertFalse(Paste::isValidId('my.paste'), 'dot is invalid');
        $this->assertFalse(Paste::isValidId('../bar/baz'), 'path attack is invalid');
        $this->assertFalse(Paste::isValidName('admin'), 'reserved name is invalid');
        $this->assertFalse(Paste::isValidName('pasteid'), 'request parameter is reserved');
        $this->assertFalse(Paste::isValidName('index'), 'reserved name is invalid');
        $this->assertTrue(Paste::isValidTag('java'));
        $this->assertFalse(Paste::isValidTag('java script'), 'space in tag is invalid');
        $this->assertFalse(Paste::isValidTag(str_repeat('a', 33)), 'tag too long');
    }

    public function testDuplicateNameIsRejected()
    {
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'taken-name']);
        $paste->setData($data);
        $paste->store();

        $this->expectException(TranslatedException::class);
        $second = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'taken-name']);
        $second->setData($data);
        $second->store();
    }

    public function testInvalidNameIsRejected()
    {
        $this->expectException(TranslatedException::class);
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'Invalid Name']);
        $paste->setData($data);
    }

    public function testSetExpiration()
    {
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'expiring-paste']);
        $paste->setData($data);
        $paste->store();

        $paste = $this->_model->getPaste('expiring-paste');
        $paste->setExpiration(time() + 3600);
        $stored = $paste->getStored();
        $this->assertArrayHasKey('expire_date', $stored['meta']);
        $this->assertGreaterThan(time(), $stored['meta']['expire_date']);

        $paste->setExpiration(null);
        $stored = $paste->getStored();
        $this->assertArrayNotHasKey('expire_date', $stored['meta']);
    }

    public function testRenameKeepsPasteAndComments()
    {
        $paste = $this->_model->getPaste();
        // enable the discussion by adjusting the authenticated data
        $data = Helper::getPastePost(['name' => 'old-name', 'tag' => 'code']);
        $data['adata'][Paste::ADATA_OPEN_DISCUSSION] = 1;
        $paste->setData($data);
        $paste->store();

        $comment = $paste->getComment('old-name');
        $commentData = Helper::getCommentPost();
        unset($commentData['pasteid'], $commentData['parentid']);
        $comment->setData($commentData);
        $comment->store();
        $this->assertCount(1, $paste->getComments());

        $paste = $this->_model->getPaste('old-name');
        $paste->rename('new-name');

        $this->assertSame('new-name', $paste->getId());
        $this->assertFalse($this->_model->getPaste('old-name')->exists(), 'old name no longer exists');
        $renamed = $this->_model->getPaste('new-name');
        $this->assertTrue($renamed->exists(), 'new name exists');

        $stored = $renamed->getStored();
        $this->assertSame('new-name', $stored['meta']['name']);
        $this->assertSame('code', $stored['meta']['tag'], 'tag is preserved');
        $this->assertContains('old-name', $stored['meta']['previous_ids'], 'previous ID is remembered');

        $comments = array_values($renamed->getComments());
        $this->assertCount(1, $comments, 'comment survived the rename');
        $this->assertSame('new-name', $comments[0]['parentid'], 'top-level comment parent points to new name');
    }

    public function testRenameToExistingNameIsRejected()
    {
        foreach (['first-name', 'second-name'] as $name) {
            $paste = $this->_model->getPaste();
            $data = Helper::getPastePost(['name' => $name]);
            $paste->setData($data);
            $paste->store();
        }

        $this->expectException(TranslatedException::class);
        $this->_model->getPaste('first-name')->rename('second-name');
    }

    public function testRenameToInvalidNameIsRejected()
    {
        $paste = $this->_model->getPaste();
        $data = Helper::getPastePost(['name' => 'valid-name']);
        $paste->setData($data);
        $paste->store();

        $this->expectException(TranslatedException::class);
        $this->_model->getPaste('valid-name')->rename('invalid name');
    }
}
