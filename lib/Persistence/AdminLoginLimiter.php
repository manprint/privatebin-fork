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

namespace PrivateBin\Persistence;

use Exception;
use PrivateBin\Data\AbstractData;
use PrivateBin\Json;

/**
 * AdminLoginLimiter
 *
 * Rate limiter for failed administration logins, keyed by client.
 */
class AdminLoginLimiter
{
    /**
     * store namespace
     *
     * @const string
     */
    const NAMESPACE_NAME = 'admin_login';

    /**
     * number of failed attempts before the client is locked out
     *
     * @const int
     */
    const MAX_ATTEMPTS = 5;

    /**
     * number of seconds a client is locked out
     *
     * @const int
     */
    const LOCKOUT_SECONDS = 900;

    /**
     * maximum number of remembered clients
     *
     * @const int
     */
    const MAX_CLIENTS = 100;

    /**
     * data storage
     *
     * @access private
     * @static
     * @var AbstractData
     */
    private static $_store = null;

    /**
     * key identifying the client
     *
     * @access private
     * @static
     * @var string
     */
    private static $_clientKey = '';

    /**
     * Set the data storage.
     *
     * @access public
     * @static
     * @param  AbstractData $store
     */
    public static function setStore(AbstractData $store)
    {
        self::$_store = $store;
    }

    /**
     * Set the key identifying the current client.
     *
     * @access public
     * @static
     * @param  string $clientKey
     */
    public static function setClientKey($clientKey)
    {
        self::$_clientKey = (string) $clientKey;
    }

    /**
     * Check if the current client is locked out.
     *
     * @access public
     * @static
     * @return bool
     */
    public static function isLockedOut()
    {
        $attempts = self::_load();
        if (!array_key_exists(self::$_clientKey, $attempts) || !is_array($attempts[self::$_clientKey])) {
            return false;
        }
        return (int) ($attempts[self::$_clientKey]['until'] ?? 0) > time();
    }

    /**
     * Record a failed login attempt.
     *
     * @access public
     * @static
     */
    public static function recordFailure()
    {
        $attempts = self::_load();
        $now = time();
        foreach ($attempts as $key => $entry) {
            if (
                (int) ($entry['until'] ?? 0) < $now - self::LOCKOUT_SECONDS &&
                (int) ($entry['first'] ?? 0) < $now - self::LOCKOUT_SECONDS
            ) {
                unset($attempts[$key]);
            }
        }
        $entry = $attempts[self::$_clientKey] ?? ['n' => 0, 'first' => $now, 'until' => 0];
        if (!is_array($entry)) {
            $entry = ['n' => 0, 'first' => $now, 'until' => 0];
        }
        if ((int) ($entry['first'] ?? 0) < $now - self::LOCKOUT_SECONDS) {
            $entry = ['n' => 0, 'first' => $now, 'until' => 0];
        }
        ++$entry['n'];
        if ($entry['n'] >= self::MAX_ATTEMPTS) {
            $entry['until'] = $now + self::LOCKOUT_SECONDS;
        }
        $attempts[self::$_clientKey] = $entry;
        if (count($attempts) > self::MAX_CLIENTS) {
            $attempts = array_slice($attempts, -self::MAX_CLIENTS, null, true);
        }
        self::_save($attempts);
    }

    /**
     * Clear the failed login attempts of the current client.
     *
     * @access public
     * @static
     */
    public static function clear()
    {
        $attempts = self::_load();
        if (!array_key_exists(self::$_clientKey, $attempts)) {
            return;
        }
        unset($attempts[self::$_clientKey]);
        self::_save($attempts);
    }

    /**
     * Load the stored attempts.
     *
     * @access private
     * @static
     * @return array
     */
    private static function _load()
    {
        if (self::$_store === null) {
            return [];
        }
        $raw = self::$_store->getValue(self::NAMESPACE_NAME);
        $attempts = json_decode((string) $raw, true);
        return is_array($attempts) ? $attempts : [];
    }

    /**
     * Save the stored attempts.
     *
     * @access private
     * @static
     * @param  array $attempts
     */
    private static function _save(array $attempts)
    {
        if (self::$_store === null) {
            return;
        }
        try {
            self::$_store->setValue(Json::encode($attempts), self::NAMESPACE_NAME);
        } catch (Exception $e) {
            error_log('Error while storing failed admin login attempts: ' . $e->getMessage());
        }
    }
}
