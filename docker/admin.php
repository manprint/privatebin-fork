<?php declare(strict_types=1);
/**
 * PrivateBin administration front controller for the container layout.
 */
define('PATH', '/srv/');
define('PUBLIC_PATH', __DIR__);
require PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
new PrivateBin\Admin;
