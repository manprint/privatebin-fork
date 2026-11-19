<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PrivateBin\Admin;
use PrivateBin\Configuration;
use PrivateBin\Data\Filesystem;
use PrivateBin\Json;
use PrivateBin\Persistence\ServerSalt;
use PrivateBin\View;

class AdminTest extends TestCase
{
    private $_conf;

    private $_store;

    private $_path;

    public function setUp(): void
    {
        $this->_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_admin_data';
        if (!is_dir($this->_path)) {
            mkdir($this->_path);
        }
        $options = parse_ini_file(CONF_SAMPLE, true);
        $options['model'] = ['class' => 'Filesystem'];
        $options['model_options'] = ['dir' => $this->_path];
        $options['admin'] = [
            'enabled' => true,
            'tokenhash' => hash('sha256', 'Abc1234567890xy'),
        ];
        Helper::confBackup();
        Helper::createIniFile(CONF, $options);
        ServerSalt::setStore(new Filesystem($options['model_options']));
        $this->_conf = new Configuration;
        $this->_store = new Filesystem($options['model_options']);

        $this->_createPaste('alpha-paste', 'code', 1000, null);
        $this->_createPaste('beta-paste', 'shell', 2000, 500);
        $this->_createPaste('gamma-paste', 'code', 3000, null);
    }

    public function tearDown(): void
    {
        unlink(CONF);
        Helper::confRestore();
        Helper::rmDir($this->_path);
    }

    private function _createPaste($id, $tag, $created, $expireDate)
    {
        $data = Helper::getPaste(['name' => $id, 'tag' => $tag]);
        unset($data['meta']['salt']);
        $data['meta']['created'] = $created;
        if ($expireDate !== null) {
            $data['meta']['expire_date'] = $expireDate;
        }
        $this->assertTrue($this->_store->create($id, $data), 'created ' . $id);
    }

    public function testClientKeyIsStable()
    {
        $key = Admin::clientKey('192.0.2.1');
        $this->assertMatchesRegularExpression('#\A[a-f0-9]{64}\z#', $key);
        $this->assertSame($key, Admin::clientKey('192.0.2.1'), 'same client yields the same key');
        $this->assertNotSame($key, Admin::clientKey('192.0.2.2'), 'different clients yield different keys');
    }

    public function testRenderLoginTemplate()
    {
        $page = new View;
        $page->assign('AUTHENTICATED', false);
        $page->assign('CSRF', 'csrf-token');
        $i18n = ['All' => 'All'];
        $page->assign('I18N', Json::encode($i18n));
        $page->assign('NAME', 'PrivateBin');
        $page->assign('SRI', []);
        $page->assign('VERSION', 'test');
        ob_start();
        $page->draw('admin');
        $html = ob_get_contents();
        ob_end_clean();
        $this->assertStringContainsString('id="loginform"', $html);
        $this->assertStringContainsString('data-i18n=', $html);
        $this->assertStringContainsString('data-csrf="csrf-token"', $html);
        $this->assertStringNotContainsString('id="filterform"', $html);
    }

    public function testRenderAuthenticatedTemplate()
    {
        $page = new View;
        $page->assign('AUTHENTICATED', true);
        $page->assign('CSRF', 'csrf-token');
        $i18n = ['All' => 'All'];
        $page->assign('I18N', Json::encode($i18n));
        $page->assign('NAME', 'PrivateBin');
        $page->assign('SRI', []);
        $page->assign('VERSION', 'test');
        ob_start();
        $page->draw('admin');
        $html = ob_get_contents();
        ob_end_clean();
        $this->assertStringContainsString('id="filterform"', $html);
        $this->assertStringContainsString('id="pastes"', $html);
        $this->assertStringContainsString('data-authenticated="1"', $html);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function testRendersAdminPage()
    {
        $_GET = [];
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_SERVER['REMOTE_ADDR'] = '::1';
        $_SERVER['HTTP_HOST'] = 'example.com';
        ob_start();
        new Admin($this->_conf);
        $html = ob_get_contents();
        ob_end_clean();
        $this->assertStringContainsString('id="loginform"', $html);
        $this->assertStringContainsString('data-i18n=', $html);
        $this->assertStringContainsString('"All"', html_entity_decode($html));
    }

    public function testCspHeaderAllowsOwnOriginConnections()
    {
        $this->assertStringContainsString("default-src 'none'", Admin::CSP_HEADER);
        $this->assertStringContainsString("connect-src 'self'", Admin::CSP_HEADER, 'the admin frontend fetches admin.php, a missing connect-src blocks it');
    }

    public function testVerifyToken()
    {
        $hash = hash('sha256', 'Abc1234567890xy');
        $this->assertTrue(Admin::verifyToken('Abc1234567890xy', $hash), 'valid token');
        $this->assertFalse(Admin::verifyToken('Abc1234567890xx', $hash), 'wrong token');
        $this->assertFalse(Admin::verifyToken('too-short', $hash), 'malformed token');
        $this->assertFalse(Admin::verifyToken('Abc1234567890xy', 'not-a-hash'), 'malformed hash');
        $this->assertFalse(Admin::verifyToken('Abc1234567890xy!', $hash), 'non-alphanumeric token');
    }

    public function testIsEnabled()
    {
        $this->assertTrue($this->_conf->getKey('enabled', 'admin'));
        $this->assertTrue(Admin::isEnabledFor($this->_conf));

        $options = parse_ini_file(CONF, true);
        $options['admin']['enabled'] = false;
        Helper::createIniFile(CONF, $options);
        $this->assertFalse(Admin::isEnabledFor(new Configuration));

        $options['admin']['enabled'] = true;
        $options['admin']['tokenhash'] = '';
        Helper::createIniFile(CONF, $options);
        $this->assertFalse(Admin::isEnabledFor(new Configuration));
    }

    public function testCollectPastesReturnsMetaDataOnly()
    {
        $result = Admin::collectPastes($this->_store);
        $this->assertSame(3, $result['total']);
        $this->assertSame(1, $result['page']);
        $this->assertSame(1, $result['pages']);
        $this->assertSame(['code', 'shell'], $result['tags']);

        $item = $result['items'][0];
        $this->assertSame('gamma-paste', $item['id'], 'sorted newest first by default');
        $this->assertSame('code', $item['tag']);
        $this->assertSame(3000, $item['created']);
        $this->assertNull($item['expire_date']);
        $this->assertFalse($item['expired']);
        $this->assertArrayNotHasKey('ct', $item, 'document contents are not exposed');
        $this->assertArrayNotHasKey('adata', $item, 'document contents are not exposed');
    }

    public function testCollectPastesSortsAscending()
    {
        $result = Admin::collectPastes($this->_store, ['sort' => 'created_asc']);
        $this->assertSame('alpha-paste', $result['items'][0]['id']);
        $this->assertSame('gamma-paste', $result['items'][2]['id']);
    }

    public function testCollectPastesFiltersByQuery()
    {
        $result = Admin::collectPastes($this->_store, ['q' => 'alp']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('alpha-paste', $result['items'][0]['id']);

        $result = Admin::collectPastes($this->_store, ['q' => 'GAMMA']);
        $this->assertSame(1, $result['total'], 'search is case insensitive');

        $result = Admin::collectPastes($this->_store, ['q' => 'code']);
        $this->assertSame(2, $result['total'], 'the query also matches tags');
    }

    public function testCollectPastesFiltersByTag()
    {
        $result = Admin::collectPastes($this->_store, ['tag' => 'code']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(['gamma-paste', 'alpha-paste'], array_column($result['items'], 'id'));
    }

    public function testCollectPastesFiltersByStatus()
    {
        $result = Admin::collectPastes($this->_store, ['status' => 'expired']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('beta-paste', $result['items'][0]['id']);
        $this->assertTrue($result['items'][0]['expired']);

        $result = Admin::collectPastes($this->_store, ['status' => 'active']);
        $this->assertSame(2, $result['total']);
        $this->assertSame(['gamma-paste', 'alpha-paste'], array_column($result['items'], 'id'));
    }

    public function testCollectPastesFiltersByDate()
    {
        $result = Admin::collectPastes($this->_store, ['from' => 1500, 'to' => 2500]);
        $this->assertSame(1, $result['total']);
        $this->assertSame('beta-paste', $result['items'][0]['id']);

        $result = Admin::collectPastes($this->_store, ['from' => '1970-01-01', 'to' => '2038-01-01']);
        $this->assertSame(3, $result['total'], 'ISO dates are accepted');
    }

    public function testCollectPastesPaginates()
    {
        $result = Admin::collectPastes($this->_store, ['perpage' => 2, 'page' => 1]);
        $this->assertSame(2, $result['pages']);
        $this->assertCount(2, $result['items']);

        $result = Admin::collectPastes($this->_store, ['perpage' => 2, 'page' => 2]);
        $this->assertCount(1, $result['items']);
        $this->assertSame('alpha-paste', $result['items'][0]['id']);

        $result = Admin::collectPastes($this->_store, ['perpage' => 2, 'page' => 99]);
        $this->assertSame(2, $result['page'], 'page is clamped to the last page');
    }

    public function testCollectPastesFiltersSingleId()
    {
        $result = Admin::collectPastes($this->_store, ['id' => 'beta-paste']);
        $this->assertSame(1, $result['total']);
        $this->assertSame('beta-paste', $result['items'][0]['id']);
    }
}
