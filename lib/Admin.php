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
use PrivateBin\Data\AbstractData;
use PrivateBin\Exception\TranslatedException;
use PrivateBin\Model\Paste;
use PrivateBin\Persistence\AdminLoginLimiter;
use PrivateBin\Persistence\ServerSalt;

/**
 * Admin
 *
 * Administration interface for the metadata of pastes.
 *
 * Pastes remain end-to-end encrypted, therefore the administration interface can
 * only manage the unencrypted meta data (name, tag, dates) and not the document
 * content itself.
 */
class Admin
{
    /**
     * session name used by the administration interface
     *
     * @const string
     */
    const SESSION_NAME = 'PrivateBinAdmin';

    /**
     * number of seconds of inactivity before the session expires
     *
     * @const int
     */
    const SESSION_TIMEOUT = 1800;

    /**
     * Configuration.
     *
     * @access private
     * @var Configuration
     */
    private $_conf;

    /**
     * Data storage.
     *
     * @access private
     * @var AbstractData
     */
    private $_store;

    /**
     * Instance constructor.
     *
     * @access public
     * @param ?Configuration $config
     */
    public function __construct(?Configuration $config = null)
    {
        $this->_conf = $config ?? new Configuration();
        if (!$this->_isEnabled()) {
            http_response_code(404);
            return;
        }

        $this->_store = (new Model($this->_conf))->getStore();
        ServerSalt::setStore($this->_store);
        AdminLoginLimiter::setStore($this->_store);
        $this->_startSession();

        $action = isset($_GET['action']) ? (string) $_GET['action'] : '';
        try {
            switch ($action) {
                case '':
                    $this->_render();
                    break;
                case 'login':
                    $this->_login();
                    break;
                case 'logout':
                    $this->_logout();
                    break;
                case 'list':
                    $this->_list();
                    break;
                case 'rename':
                    $this->_rename();
                    break;
                case 'expire':
                    $this->_expire();
                    break;
                case 'delete':
                    $this->_delete();
                    break;
                default:
                    $this->_json(['status' => 1, 'message' => 'Unknown action.'], 404);
            }
        } catch (TranslatedException $e) {
            $this->_json(['status' => 1, 'message' => $e->getMessage()], 400);
        } catch (Exception $e) {
            error_log('Administration error: ' . $e->getMessage());
            $this->_json(['status' => 1, 'message' => 'Internal error.'], 500);
        }
    }

    /**
     * Check if the administration interface is enabled and configured.
     *
     * @access public
     * @return bool
     */
    public function _isEnabled()
    {
        return self::isEnabledFor($this->_conf);
    }

    /**
     * Check if the administration interface is enabled and configured.
     *
     * @access public
     * @static
     * @param  Configuration $conf
     * @return bool
     */
    public static function isEnabledFor(Configuration $conf)
    {
        return $conf->getKey('enabled', 'admin') &&
            preg_match('#\A[a-f0-9]{64}\z#', (string) $conf->getKey('tokenhash', 'admin')) === 1;
    }

    /**
     * Verify an administration token against the configured hash.
     *
     * @access public
     * @static
     * @param  string $token
     * @param  string $hash
     * @return bool
     */
    public static function verifyToken($token, $hash)
    {
        return is_string($token) &&
            preg_match('#\A[A-Za-z0-9]{15}\z#', $token) === 1 &&
            is_string($hash) &&
            preg_match('#\A[a-f0-9]{64}\z#', $hash) === 1 &&
            hash_equals($hash, hash('sha256', $token));
    }

    /**
     * Build a stable key identifying a client, used for rate limiting logins.
     *
     * @access public
     * @static
     * @param  string $address
     * @return string
     */
    public static function clientKey($address)
    {
        return hash('sha256', (string) $address . '|' . ServerSalt::get());
    }

    /**
     * Collect and filter the metadata of stored pastes.
     *
     * The document contents are encrypted end-to-end, therefore only the meta
     * data can be searched and filtered.
     *
     * @access public
     * @static
     * @param  AbstractData $store
     * @param  array $filters
     * @return array
     */
    public static function collectPastes(AbstractData $store, array $filters = [])
    {
        foreach (['id', 'q', 'tag', 'status', 'from', 'to', 'sort', 'page', 'perpage'] as $key) {
            if (array_key_exists($key, $filters) && !is_scalar($filters[$key])) {
                unset($filters[$key]);
            }
        }
        $id = isset($filters['id']) ? (string) $filters['id'] : '';
        $query = strtolower(trim((string) ($filters['q'] ?? '')));
        $tag = (string) ($filters['tag'] ?? '');
        $status = in_array($filters['status'] ?? '', ['active', 'expired'], true) ?
            $filters['status'] : 'all';
        $from = self::_parseDate($filters['from'] ?? null, false);
        $to = self::_parseDate($filters['to'] ?? null, true);
        $sort = ($filters['sort'] ?? 'created_desc') === 'created_asc' ? 'asc' : 'desc';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perpage = min(100, max(1, (int) ($filters['perpage'] ?? 50)));
        $now = time();

        $pastes = [];
        $tags = [];
        foreach ($store->getAllPastes() as $pasteId) {
            if ($id !== '' && $pasteId !== $id) {
                continue;
            }
            $data = $store->read($pasteId);
            if (!is_array($data)) {
                continue;
            }
            $meta = is_array($data['meta'] ?? null) ? $data['meta'] : [];
            $name = isset($meta['name']) && Paste::isValidName($meta['name']) ? $meta['name'] : null;
            $pasteTag = isset($meta['tag']) && Paste::isValidTag($meta['tag']) ? $meta['tag'] : null;
            if ($pasteTag !== null) {
                $tags[$pasteTag] = true;
            }
            $created = isset($meta['created']) ? (int) $meta['created'] : 0;
            $expireDate = isset($meta['expire_date']) ? (int) $meta['expire_date'] : null;
            $pastes[] = [
                'id'          => $pasteId,
                'name'        => $name,
                'tag'         => $pasteTag,
                'created'     => $created,
                'expire_date' => $expireDate,
                'expired'     => $expireDate !== null && $expireDate < $now,
                'size'        => isset($data['ct']) && is_string($data['ct']) ? strlen($data['ct']) : 0,
                'formatter'   => $data['adata'][1] ?? null,
            ];
        }

        $pastes = array_values(array_filter($pastes, function ($paste) use ($query, $tag, $status, $from, $to) {
            if ($query !== '') {
                $haystack = strtolower(implode(' ', array_filter([$paste['id'], $paste['name'], $paste['tag']])));
                if (strpos($haystack, $query) === false) {
                    return false;
                }
            }
            if ($tag !== '' && $paste['tag'] !== $tag) {
                return false;
            }
            if ($status === 'active' && $paste['expired']) {
                return false;
            }
            if ($status === 'expired' && !$paste['expired']) {
                return false;
            }
            if ($from !== null && $paste['created'] < $from) {
                return false;
            }
            if ($to !== null && $paste['created'] > $to) {
                return false;
            }
            return true;
        }));

        usort($pastes, function ($a, $b) use ($sort) {
            if ($a['created'] === $b['created']) {
                return strcmp($a['id'], $b['id']);
            }
            return $sort === 'asc' ?
                $a['created'] <=> $b['created'] :
                $b['created'] <=> $a['created'];
        });

        $total = count($pastes);
        $pages = max(1, (int) ceil($total / $perpage));
        $page = min($page, $pages);
        $items = array_slice($pastes, ($page - 1) * $perpage, $perpage);

        $tags = array_keys($tags);
        sort($tags);
        return [
            'items'   => $items,
            'tags'    => $tags,
            'total'   => $total,
            'page'    => $page,
            'pages'   => $pages,
            'perpage' => $perpage,
        ];
    }

    /**
     * Parse a date filter, accepting unix timestamps and ISO dates.
     *
     * @access private
     * @static
     * @param  mixed $value
     * @param  bool $endOfDay
     * @return int|null
     */
    private static function _parseDate($value, $endOfDay)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        $timestamp = strtotime((string) $value);
        if ($timestamp === false) {
            return null;
        }
        if (preg_match('#\A\d{4}-\d{2}-\d{2}\z#', (string) $value)) {
            $timestamp = $endOfDay ? $timestamp + 86399 : $timestamp;
        }
        return $timestamp;
    }

    /**
     * Start the administration session.
     *
     * @access private
     */
    private function _startSession()
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $params = [
            'lifetime' => 0,
            'path'     => '/',
            'httponly' => true,
            'samesite' => 'Strict',
            'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ||
                ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        ];
        if (PHP_VERSION_ID >= 70300) {
            session_set_cookie_params($params);
        } else {
            session_set_cookie_params(
                $params['lifetime'],
                $params['path'] . '; samesite=' . $params['samesite'],
                '',
                $params['secure'],
                $params['httponly']
            );
        }
        ini_set('session.use_strict_mode', '1');
        session_name(self::SESSION_NAME);
        session_start();
        if (!isset($_SESSION['csrf'])) {
            $_SESSION['csrf'] = bin2hex(random_bytes(32));
        }
    }

    /**
     * Check if the current session is authenticated.
     *
     * @access private
     * @return bool
     */
    private function _isAuthenticated()
    {
        if (empty($_SESSION['authenticated'])) {
            return false;
        }
        if (time() - (int) ($_SESSION['last_activity'] ?? 0) > self::SESSION_TIMEOUT) {
            unset($_SESSION['authenticated'], $_SESSION['last_activity']);
            return false;
        }
        $_SESSION['last_activity'] = time();
        return true;
    }

    /**
     * Check the CSRF token of the current request.
     *
     * @access private
     */
    private function _requireCsrf()
    {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($_SESSION['csrf']) || !is_string($token) || !hash_equals($_SESSION['csrf'], $token)) {
            $this->_json(['status' => 1, 'message' => 'Invalid CSRF token.'], 403);
            exit;
        }
    }

    /**
     * Require an authenticated session.
     *
     * @access private
     */
    private function _requireAuthentication()
    {
        if (!$this->_isAuthenticated()) {
            $this->_json(['status' => 1, 'message' => 'Not authenticated.'], 401);
            exit;
        }
    }

    /**
     * Handle a login attempt.
     *
     * @access private
     */
    private function _login()
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->_json(['status' => 1, 'message' => 'Method not allowed.'], 405);
            return;
        }
        AdminLoginLimiter::setClientKey(
            self::clientKey($_SERVER['REMOTE_ADDR'] ?? 'unknown')
        );
        if (AdminLoginLimiter::isLockedOut()) {
            $this->_json(['status' => 1, 'message' => 'Too many failed attempts. Try again later.'], 429);
            return;
        }
        $body = $this->_getJsonBody();
        $token = $body['token'] ?? '';
        if (!self::verifyToken($token, (string) $this->_conf->getKey('tokenhash', 'admin'))) {
            AdminLoginLimiter::recordFailure();
            $this->_json(['status' => 1, 'message' => 'Invalid token.'], 401);
            return;
        }
        session_regenerate_id(true);
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
        $_SESSION['authenticated'] = true;
        $_SESSION['last_activity'] = time();
        AdminLoginLimiter::clear();
        $this->_json(['status' => 0, 'csrf' => $_SESSION['csrf']]);
    }

    /**
     * Handle a logout request.
     *
     * @access private
     */
    private function _logout()
    {
        $this->_requireAuthentication();
        $this->_requireCsrf();
        $_SESSION = [];
        session_destroy();
        $this->_json(['status' => 0]);
    }

    /**
     * Return the filtered paste list as JSON.
     *
     * @access private
     */
    private function _list()
    {
        $this->_requireAuthentication();
        $result = self::collectPastes($this->_store, $_GET);
        $this->_json(['status' => 0] + $result);
    }

    /**
     * Rename a paste.
     *
     * @access private
     */
    private function _rename()
    {
        $this->_requireAuthentication();
        $this->_requireCsrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->_json(['status' => 1, 'message' => 'Method not allowed.'], 405);
            return;
        }
        $body = $this->_getJsonBody();
        $paste = $this->_getPaste($body['id'] ?? '');
        $paste->rename($body['name'] ?? '');
        $this->_json(['status' => 0, 'id' => $paste->getId()]);
    }

    /**
     * Change the expiration of a paste.
     *
     * @access private
     */
    private function _expire()
    {
        $this->_requireAuthentication();
        $this->_requireCsrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->_json(['status' => 1, 'message' => 'Method not allowed.'], 405);
            return;
        }
        $body = $this->_getJsonBody();
        if (!array_key_exists('expire', $body) || !is_numeric($body['expire']) || (int) $body['expire'] < 0) {
            throw new TranslatedException('Invalid expiration.', 85);
        }
        $expire = (int) $body['expire'];
        if ($expire > 315360000) {
            throw new TranslatedException('Invalid expiration.', 85);
        }
        $paste = $this->_getPaste($body['id'] ?? '');
        $paste->setExpiration($expire > 0 ? time() + $expire : null);
        $this->_json(['status' => 0, 'expire_date' => $expire > 0 ? time() + $expire : null]);
    }

    /**
     * Delete a paste.
     *
     * @access private
     */
    private function _delete()
    {
        $this->_requireAuthentication();
        $this->_requireCsrf();
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            $this->_json(['status' => 1, 'message' => 'Method not allowed.'], 405);
            return;
        }
        $body = $this->_getJsonBody();
        $paste = $this->_getPaste($body['id'] ?? '');
        $paste->delete();
        $this->_json(['status' => 0]);
    }

    /**
     * Get a paste model for the given ID, ensuring it exists.
     *
     * @access private
     * @param  mixed $id
     * @throws TranslatedException
     * @return Paste
     */
    private function _getPaste($id)
    {
        if (!is_string($id) || !Paste::isValidId($id)) {
            throw new TranslatedException('Invalid document ID.', 60);
        }
        $paste = (new Model($this->_conf))->getPaste($id);
        if (!$paste->exists()) {
            throw new TranslatedException(Controller::GENERIC_ERROR, 64);
        }
        return $paste;
    }

    /**
     * Read the JSON request body.
     *
     * @access private
     * @return array
     */
    private function _getJsonBody()
    {
        $body = file_get_contents('php://input');
        if (empty($body)) {
            return [];
        }
        try {
            $data = Json::decode($body);
        } catch (Exception $e) {
            return [];
        }
        return is_array($data) ? $data : [];
    }

    /**
     * Render the administration page.
     *
     * @access private
     */
    private function _render()
    {
        header('Content-Security-Policy: default-src \'none\'; base-uri \'self\'; form-action \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data:; frame-ancestors \'none\'');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: deny');
        header('Referrer-Policy: no-referrer');
        header('Cache-Control: no-store');
        header('X-Robots-Tag: noindex, nofollow');

        $authenticated = $this->_isAuthenticated();
        $page = new View;
        $page->assign('NAME', $this->_conf->getKey('name'));
        $page->assign('VERSION', Controller::VERSION);
        $page->assign('AUTHENTICATED', $authenticated);
        $page->assign('CSRF', $_SESSION['csrf']);
        $translations = $this->_getClientTranslations();
        $page->assign('I18N', Json::encode($translations));
        $page->assign('SRI', $this->_conf->getSection('sri'));
        $page->draw('admin');
    }

    /**
     * Get the translations used by the administration frontend.
     *
     * They are passed as a JSON data attribute, so that no inline script is
     * needed and the content security policy stays strict.
     *
     * @access private
     * @return array
     */
    private function _getClientTranslations()
    {
        $keys = [
            'All', 'Delete', 'Delete this document permanently?', 'Expiration',
            'Expired', 'Invalid number of days.', 'Never',
            'New name (4 to 64 characters: a-z, 0-9, - and _):',
            'No documents found.', 'Not authenticated.', 'Page', 'Rename',
            'Request failed.', 'The document was deleted.', 'The document was renamed.',
            'The expiration was updated.', 'The server returned an invalid response.',
            'Validity in days (0 = no expiration):', 'document', 'documents',
        ];
        $translations = [];
        foreach ($keys as $key) {
            $translations[$key] = I18n::_($key);
        }
        return $translations;
    }

    /**
     * Output a JSON response and stop.
     *
     * @access private
     * @param  array $data
     * @param  int $status
     */
    private function _json(array $data, $status = 200)
    {
        http_response_code($status);
        header('Content-Type: application/json');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo Json::encode($data);
    }
}
