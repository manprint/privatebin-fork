<?php declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PrivateBin\Data\Filesystem;
use PrivateBin\Json;
use PrivateBin\Persistence\AdminLoginLimiter;

class AdminLoginLimiterTest extends TestCase
{
    private $_store;

    private $_path;

    public function setUp(): void
    {
        $this->_path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'privatebin_login_data';
        if (!is_dir($this->_path)) {
            mkdir($this->_path);
        }
        $this->_store = new Filesystem(['dir' => $this->_path]);
        AdminLoginLimiter::setStore($this->_store);
        AdminLoginLimiter::setClientKey('client-a');
    }

    public function tearDown(): void
    {
        Helper::rmDir($this->_path);
    }

    public function testLocksOutAfterRepeatedFailures()
    {
        $this->assertFalse(AdminLoginLimiter::isLockedOut(), 'not locked out initially');
        for ($i = 0; $i < AdminLoginLimiter::MAX_ATTEMPTS - 1; ++$i) {
            AdminLoginLimiter::recordFailure();
            $this->assertFalse(AdminLoginLimiter::isLockedOut(), 'not locked out after ' . ($i + 1) . ' failures');
        }
        AdminLoginLimiter::recordFailure();
        $this->assertTrue(AdminLoginLimiter::isLockedOut(), 'locked out after ' . AdminLoginLimiter::MAX_ATTEMPTS . ' failures');
    }

    public function testClearUnlocksClient()
    {
        for ($i = 0; $i < AdminLoginLimiter::MAX_ATTEMPTS; ++$i) {
            AdminLoginLimiter::recordFailure();
        }
        $this->assertTrue(AdminLoginLimiter::isLockedOut());
        AdminLoginLimiter::clear();
        $this->assertFalse(AdminLoginLimiter::isLockedOut(), 'successful login clears the counter');
    }

    public function testOtherClientsAreNotAffected()
    {
        for ($i = 0; $i < AdminLoginLimiter::MAX_ATTEMPTS; ++$i) {
            AdminLoginLimiter::recordFailure();
        }
        AdminLoginLimiter::setClientKey('client-b');
        $this->assertFalse(AdminLoginLimiter::isLockedOut(), 'other clients are not locked out');
    }

    public function testExpiredLockoutIsIgnored()
    {
        $attempts = [
            'client-a' => [
                'n'     => AdminLoginLimiter::MAX_ATTEMPTS,
                'first' => time() - AdminLoginLimiter::LOCKOUT_SECONDS - 10,
                'until' => time() - 10,
            ],
        ];
        $this->_store->setValue(Json::encode($attempts), AdminLoginLimiter::NAMESPACE_NAME);
        $this->assertFalse(AdminLoginLimiter::isLockedOut(), 'expired lockout is ignored');
    }

    public function testStaleEntriesArePruned()
    {
        $attempts = [
            'stale-client' => [
                'n'     => 1,
                'first' => time() - AdminLoginLimiter::LOCKOUT_SECONDS - 10,
                'until' => 0,
            ],
        ];
        $this->_store->setValue(Json::encode($attempts), AdminLoginLimiter::NAMESPACE_NAME);
        AdminLoginLimiter::recordFailure();
        $stored = json_decode($this->_store->getValue(AdminLoginLimiter::NAMESPACE_NAME), true);
        $this->assertArrayNotHasKey('stale-client', $stored, 'stale entries are removed');
        $this->assertArrayHasKey('client-a', $stored);
    }
}
