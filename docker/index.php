<?php declare(strict_types=1);
/**
 * PrivateBin front controller for the container layout.
 *
 * The application code and vendor directory live outside the document root.
 */
define('PATH', '/srv/');
define('PUBLIC_PATH', __DIR__);
require PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
new PrivateBin\Controller;
