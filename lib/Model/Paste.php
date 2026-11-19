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

namespace PrivateBin\Model;

use PrivateBin\Controller;
use PrivateBin\Exception\TranslatedException;
use PrivateBin\Persistence\ServerSalt;

/**
 * Paste
 *
 * Model of a PrivateBin paste.
 */
class Paste extends AbstractModel
{
    /**
     * authenticated data index of paste formatter (plaintext/syntaxhighlighting/markdown)
     *
     * @const int
     */
    const ADATA_FORMATTER = 1;

    /**
     * authenticated data index of open-discussion flag (0/1)
     *
     * @const int
     */
    const ADATA_OPEN_DISCUSSION = 2;

    /**
     * authenticated data index of burn-after-reading flag (0/1)
     *
     * @const int
     */
    const ADATA_BURN_AFTER_READING = 3;

    /**
     * maximum length of a user defined paste name
     *
     * @const int
     */
    const NAME_MAX_LENGTH = 64;

    /**
     * minimum length of a user defined paste name
     *
     * The filesystem backend shards the storage path using the first four
     * characters of the ID, therefore shorter names are not supported.
     *
     * @const int
     */
    const NAME_MIN_LENGTH = 4;

    /**
     * maximum length of a tag
     *
     * @const int
     */
    const TAG_MAX_LENGTH = 32;

    /**
     * maximum number of previous IDs remembered for a renamed paste
     *
     * Comments authenticate the paste ID they were created for. Keeping the
     * previous IDs allows the client to keep showing those comments after a
     * rename.
     *
     * @const int
     */
    const MAX_PREVIOUS_IDS = 5;

    /**
     * names that can not be used as paste IDs, as they would collide with
     * files or routes of the application
     *
     * @const array
     */
    const RESERVED_NAMES = [
        'admin', 'api', 'bin', 'browserconfig', 'cfg', 'css', 'data',
        'deletetoken', 'doc', 'favicon', 'i18n', 'img', 'index', 'js',
        'jsonld', 'lib', 'link', 'manifest', 'pasteid', 'raw', 'robots',
        'shortenviachhoto', 'shortenviashlink', 'shortenviayourls', 'tpl',
        'tst', 'vendor',
    ];

    /**
     * Generate an ID for the given data.
     *
     * A user defined name is used verbatim as the paste ID, unnamed pastes
     * keep using the checksum based ID.
     *
     * @access protected
     * @param  array $data
     * @return string
     */
    protected function _generateId(array &$data)
    {
        if (array_key_exists('name', $data['meta'])) {
            return $data['meta']['name'];
        }
        return hash('fnv1a64', $data['ct']);
    }

    /**
     * Validate a user defined paste name.
     *
     * @access public
     * @static
     * @param  string $name
     * @return bool
     */
    public static function isValidName($name)
    {
        if (!is_string($name) || !preg_match(
            '#\A[a-z0-9][a-z0-9_-]{' . (self::NAME_MIN_LENGTH - 1) . ',' . (self::NAME_MAX_LENGTH - 1) . '}\z#',
            $name
        )) {
            return false;
        }
        return !in_array($name, self::RESERVED_NAMES, true);
    }

    /**
     * Validate a tag.
     *
     * @access public
     * @static
     * @param  string $tag
     * @return bool
     */
    public static function isValidTag($tag)
    {
        return is_string($tag) && (bool) preg_match(
            '#\A[a-z0-9][a-z0-9_-]{0,' . (self::TAG_MAX_LENGTH - 1) . '}\z#',
            $tag
        );
    }

    /**
     * Validate ID.
     *
     * A paste ID is either the legacy 16 character hexadecimal checksum or a
     * user defined name.
     *
     * @access public
     * @static
     * @param  string $id
     * @return bool
     */
    public static function isValidId($id)
    {
        return parent::isValidId($id) || self::isValidName($id);
    }

    /**
     * Check if the given ID is a legacy checksum ID.
     *
     * @access public
     * @static
     * @param  string $id
     * @return bool
     */
    public static function isChecksumId($id)
    {
        return parent::isValidId($id);
    }

    /**
     * Get paste data.
     *
     * @access public
     * @throws TranslatedException
     * @return array
     */
    public function get()
    {
        $data = $this->_store->read($this->getId());
        if ($data === false) {
            throw new TranslatedException(Controller::GENERIC_ERROR, 64);
        }

        // check if paste has expired and delete it if necessary.
        if (array_key_exists('expire_date', $data['meta'])) {
            $now = time();
            if ($data['meta']['expire_date'] < $now) {
                $this->delete();
                throw new TranslatedException(Controller::GENERIC_ERROR, 63);
            }
            // We kindly provide the remaining time before expiration (in seconds)
            $data['meta']['time_to_live'] = $data['meta']['expire_date'] - $now;
            unset($data['meta']['expire_date']);
        }
        if (array_key_exists('created', $data['meta'])) {
            unset($data['meta']['created']);
        }

        // check if non-expired burn after reading paste needs to be deleted
        if (($data['adata'][self::ADATA_BURN_AFTER_READING] ?? 0) === 1) {
            $this->delete();
        }

        $data['comments'] = array_values($this->getComments());
        $data['comment_count'] = count($data['comments']);
        $data['comment_offset'] = 0;
        $data['@context'] = '?jsonld=paste';
        $this->_data = $data;

        return $this->_data;
    }

    /**
     * Store the paste's data.
     *
     * @access public
     * @throws TranslatedException
     */
    public function store()
    {
        // Check for improbable collision.
        if ($this->exists()) {
            throw new TranslatedException(
                array_key_exists('name', $this->_data['meta']) ?
                    'This name is already taken.' : self::COLLISION_ERROR,
                75
            );
        }

        $this->_data['meta']['salt'] = ServerSalt::generate();
        if (!array_key_exists('created', $this->_data['meta'])) {
            $this->_data['meta']['created'] = time();
        }

        // store paste
        if (
            $this->_store->create(
                $this->getId(),
                $this->_data
            ) === false
        ) {
            throw new TranslatedException('Error saving document. Sorry.', 76);
        }
    }

    /**
     * Delete the paste.
     *
     * @access public
     */
    public function delete()
    {
        $this->_store->delete($this->getId());
    }

    /**
     * Rename the paste, keeping its discussion intact.
     *
     * The old ID is remembered in the meta data, so that the client keeps
     * accepting comments that authenticated the old ID.
     *
     * @access public
     * @param  string $newId
     * @throws TranslatedException
     */
    public function rename($newId)
    {
        if (!self::isValidName($newId)) {
            throw new TranslatedException('Invalid document name. Use 4 to 64 characters: a-z, 0-9, - and _.', 82);
        }
        $oldId = $this->getId();
        if ($newId === $oldId) {
            return;
        }
        if ($this->_store->exists($newId)) {
            throw new TranslatedException('This name is already taken.', 83);
        }
        $data = $this->_store->read($oldId);
        if ($data === false) {
            throw new TranslatedException(Controller::GENERIC_ERROR, 64);
        }
        $previousIds = $data['meta']['previous_ids'] ?? [];
        $previousIds[] = $oldId;
        $previousIds = array_slice(array_values(array_unique($previousIds)), -self::MAX_PREVIOUS_IDS);
        $data['meta']['previous_ids'] = $previousIds;
        $data['meta']['name'] = $newId;
        if (!$this->_store->renamePaste($oldId, $newId, $data)) {
            throw new TranslatedException('Error saving document. Sorry.', 76);
        }
        $this->_id = $newId;
        $this->_data = $data;
    }

    /**
     * Change the expiration date of a stored paste.
     *
     * @access public
     * @param  int|null $expireDate unix timestamp or null for no expiration
     * @throws TranslatedException
     */
    public function setExpiration($expireDate)
    {
        $data = $this->_store->read($this->getId());
        if ($data === false) {
            throw new TranslatedException(Controller::GENERIC_ERROR, 64);
        }
        if ($expireDate === null) {
            unset($data['meta']['expire_date']);
        } else {
            $data['meta']['expire_date'] = (int) $expireDate;
        }
        if (!$this->_store->update($this->getId(), $data)) {
            throw new TranslatedException('Error saving document. Sorry.', 76);
        }
        $this->_data = $data;
    }

    /**
     * Get the raw stored data of the paste, including server side meta data.
     *
     * @access public
     * @throws TranslatedException
     * @return array
     */
    public function getStored()
    {
        $data = $this->_store->read($this->getId());
        if ($data === false) {
            throw new TranslatedException(Controller::GENERIC_ERROR, 64);
        }
        return $data;
    }

    /**
     * Test if paste exists in store.
     *
     * @access public
     * @return bool
     */
    public function exists()
    {
        return $this->_store->exists($this->getId());
    }

    /**
     * Get a comment, optionally a specific instance.
     *
     * @access public
     * @param string $parentId
     * @param string $commentId
     * @throws TranslatedException
     * @return Comment
     */
    public function getComment($parentId, $commentId = '')
    {
        if (!$this->exists()) {
            throw new TranslatedException(self::INVALID_DATA_ERROR, 62);
        }
        $comment = new Comment($this->_conf, $this->_store);
        $comment->setPaste($this);
        $comment->setParentId($parentId);
        if (!empty($commentId)) {
            $comment->setId($commentId);
        }
        return $comment;
    }

    /**
     * Get all comments, if any.
     *
     * @access public
     * @return array
     */
    public function getComments()
    {
        if ($this->_conf->getKey('discussiondatedisplay')) {
            return $this->_store->readComments($this->getId());
        }
        return array_map(function ($comment) {
            if (array_key_exists('created', $comment['meta'])) {
                unset($comment['meta']['created']);
            }
            return $comment;
        }, $this->_store->readComments($this->getId()));
    }

    /**
     * Generate the "delete" token.
     *
     * The token is the hmac of the pastes ID signed with the server salt.
     * The paste can be deleted by calling:
     * https://example.com/privatebin/?pasteid=<pasteid>&deletetoken=<deletetoken>
     *
     * @access public
     * @return string
     */
    public function getDeleteToken()
    {
        if (!array_key_exists('salt', $this->_data['meta'])) {
            $this->get();
        }
        return hash_hmac('sha256', $this->getId(), $this->_data['meta']['salt']);
    }

    /**
     * Check if paste has discussions enabled.
     *
     * @access public
     * @return bool
     */
    public function isOpendiscussion()
    {
        if (!array_key_exists('adata', $this->_data) && !array_key_exists('data', $this->_data)) {
            $this->get();
        }
        return ($this->_data['adata'][self::ADATA_OPEN_DISCUSSION] ?? 0) === 1;
    }

    /**
     * Sanitizes data to conform with current configuration.
     *
     * @access protected
     * @param  array $data
     */
    protected function _sanitize(array &$data)
    {
        $expiration = $data['meta']['expire'] ?? 0;
        unset($data['meta']['expire']);
        $expire_options = $this->_conf->getSection('expire_options');
        // using getKey() to ensure a default value is present
        $expire = $expire_options[$expiration] ??
            $this->_conf->getKey($this->_conf->getKey('default', 'expire'), 'expire_options');
        if ($expire > 0) {
            $data['meta']['expire_date'] = time() + $expire;
        }
    }

    /**
     * Validate data.
     *
     * @access protected
     * @param  array $data
     * @throws TranslatedException
     */
    protected function _validate(array &$data)
    {
        // reject invalid or disabled formatters
        if (!array_key_exists($data['adata'][self::ADATA_FORMATTER], $this->_conf->getSection('formatter_options'))) {
            throw new TranslatedException(self::INVALID_DATA_ERROR, 75);
        }

        // reject invalid names and tags
        if (
            (array_key_exists('name', $data['meta']) && !self::isValidName($data['meta']['name'])) ||
            (array_key_exists('tag', $data['meta']) && !self::isValidTag($data['meta']['tag']))
        ) {
            throw new TranslatedException(self::INVALID_DATA_ERROR, 84);
        }

        // discussion requested, but disabled in config or burn after reading requested as well, or invalid integer
        if (
            ($data['adata'][self::ADATA_OPEN_DISCUSSION] === 1 && (
                !$this->_conf->getKey('discussion') ||
                $data['adata'][self::ADATA_BURN_AFTER_READING] === 1
            )) ||
            ($data['adata'][self::ADATA_OPEN_DISCUSSION] !== 0 && $data['adata'][self::ADATA_OPEN_DISCUSSION] !== 1)
        ) {
            throw new TranslatedException(self::INVALID_DATA_ERROR, 74);
        }

        // reject invalid burn after reading
        if (
            $data['adata'][self::ADATA_BURN_AFTER_READING] !== 0 &&
            $data['adata'][self::ADATA_BURN_AFTER_READING] !== 1
        ) {
            throw new TranslatedException(self::INVALID_DATA_ERROR, 73);
        }
    }
}
