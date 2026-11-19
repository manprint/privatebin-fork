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

use PrivateBin\Decryptor;
use PrivateBin\Json;

define('PATH', dirname(__FILE__) . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR);
require PATH . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

/**
 * Print the usage instructions.
 */
function usage()
{
    $script = basename(__FILE__);
    echo <<<USAGE
Usage: php {$script} <file|url|-> <key> [password] [output-directory]

Downloads or reads the raw (still encrypted) document, decrypts it locally and
writes the plaintext to stdout. Attachments are written to the output directory.

Arguments:
  file|url|-          path to a document saved with curl, an http(s) URL or "-"
                      to read the document from stdin
  key                 the key from the URL fragment (after the # character)
  password            optional password, if the document is protected
  output-directory    directory for attachments, defaults to the current one

Examples:
  curl -sL 'https://example.com/?pasteid=my-paste&raw=1' -o my-paste.json
  php {$script} my-paste.json 'KEY_FROM_URL'

  curl -sL 'https://example.com/?pasteid=my-paste&raw=1' | php {$script} - 'KEY_FROM_URL'

USAGE;
}

if ($argc < 3) {
    usage();
    exit(1);
}

$source = $argv[1];
$key = $argv[2];
$password = $argv[3] ?? '';
$outputDirectory = $argv[4] ?? '.';

if ($source === '-') {
    $body = stream_get_contents(STDIN);
} elseif (preg_match('#\Ahttps?://#i', $source)) {
    $body = @file_get_contents($source);
    if ($body === false) {
        fwrite(STDERR, 'Could not download ' . $source . PHP_EOL);
        exit(2);
    }
} else {
    $body = @file_get_contents($source);
    if ($body === false) {
        fwrite(STDERR, 'Could not read ' . $source . PHP_EOL);
        exit(2);
    }
}

try {
    $data = Json::decode($body);
    if (!is_array($data)) {
        throw new Exception('Not a valid document.');
    }
    $decrypted = Decryptor::decryptToArray($data, $key, $password);
} catch (Exception $e) {
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(3);
}

if (array_key_exists('paste', $decrypted)) {
    echo $decrypted['paste'], PHP_EOL;
} elseif (array_key_exists('comment', $decrypted)) {
    if (!empty($decrypted['nickname'])) {
        echo $decrypted['nickname'], ':', PHP_EOL;
    }
    echo $decrypted['comment'], PHP_EOL;
} else {
    fwrite(STDERR, 'Decrypted document has an unknown format.' . PHP_EOL);
    exit(4);
}

if (!empty($decrypted['attachment']) && is_array($decrypted['attachment'])) {
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0700, true)) {
        fwrite(STDERR, 'Could not create the output directory.' . PHP_EOL);
        exit(5);
    }
    foreach ($decrypted['attachment'] as $index => $attachment) {
        $name = $decrypted['attachment_name'][$index] ?? ('attachment-' . $index);
        $name = preg_replace('#[^A-Za-z0-9._-]#', '_', basename((string) $name));
        // never write outside the output directory, even for names like ".."
        $name = trim($name, '.');
        if ($name === '') {
            $name = 'attachment-' . $index;
        }
        if (!preg_match('#\Adata:([^;,]*)(;base64)?,#', (string) $attachment, $match)) {
            fwrite(STDERR, 'Skipping an unsupported attachment.' . PHP_EOL);
            continue;
        }
        $payload = substr($attachment, strlen($match[0]));
        $binary = empty($match[2]) ? rawurldecode($payload) : base64_decode($payload, true);
        if ($binary === false) {
            fwrite(STDERR, 'Skipping a corrupt attachment.' . PHP_EOL);
            continue;
        }
        $target = rtrim($outputDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        file_put_contents($target, $binary);
        fwrite(STDERR, 'Wrote attachment ' . $target . PHP_EOL);
    }
}
