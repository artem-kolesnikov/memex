<?php

/*
 * IF A ROUTE YOU JUST ADDED ANSWERS 404 OR 405 IN THE SUITE, THE CACHE IS STALE.
 *
 * phpunit.dist.xml forces APP_DEBUG=0, so the suite runs off a DIFFERENT
 * compiled container from the one `bin/console cache:clear --env=test` rebuilds
 * (that inherits APP_DEBUG from your shell, which is 1). A new route or service
 * is therefore invisible to the tests while every hand-run console command sees
 * it, and the failure looks like a bug in the controller.
 *
 *     rm -rf var/cache/test
 *
 * That is the fix. It cost two debugging detours on 2026-08-21 alone, once for
 * a 401 on a public route and once for a 405 on a new PATCH.
 */

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$dataDir = sys_get_temp_dir().'/memex-test-data-'.getmypid();
(new Symfony\Component\Filesystem\Filesystem())->remove($dataDir);
mkdir($dataDir, 0777, true);
putenv('MEMEX_TEST_DATA_ROOT='.$dataDir);
$_SERVER['MEMEX_DATA_DIR'] = $_ENV['MEMEX_DATA_DIR'] = $dataDir;
putenv('MEMEX_DATA_DIR='.$dataDir);
register_shutdown_function(static fn () => (new Symfony\Component\Filesystem\Filesystem())->remove($dataDir));

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/*
 * Sign in with Apple needs a real elliptic-curve private key to sign its client
 * secret with, and the suite needs Apple CONFIGURED so it exercises the offered
 * path. Generated here rather than committed: a file that looks like a private
 * key does not belong in a repository even when it guards nothing, and a
 * generated one cannot be mistaken for the operator's .p8.
 */
$appleKey = dirname(__DIR__).'/var/test-apple-key.p8';
if (!is_file($appleKey)) {
    @mkdir(\dirname($appleKey), 0777, true);
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    openssl_pkey_export($key, $pem);
    file_put_contents($appleKey, $pem);
}
