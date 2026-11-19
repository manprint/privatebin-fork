#!/usr/bin/env php
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

/**
 * Generates a 15 character administration token and the matching hash for the
 * [admin] section of the configuration.
 */
function generateToken($length = 15)
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $token = '';
    for ($i = 0; $i < $length; ++$i) {
        $token .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $token;
}

$token = generateToken();
echo 'Administration token: ', $token, PHP_EOL;
echo 'Configuration entry:', PHP_EOL, PHP_EOL;
echo '[admin]', PHP_EOL;
echo 'enabled = true', PHP_EOL;
echo 'tokenhash = "', hash('sha256', $token), '"', PHP_EOL, PHP_EOL;
echo 'Store the token in a safe place: it is not recoverable from the hash.', PHP_EOL;
