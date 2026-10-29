<?php
/**
 * PHPUnit bootstrap for running the bundle's tests INSIDE a host application.
 *
 * The bundle has no kernel of its own: the kernel tests and functional tests
 * boot the host's (KERNEL_CLASS, App\Kernel by default), against the host's
 * test database. So this loads the host's autoloader and, when it has one,
 * the host's own test bootstrap, which is what reads .env.test.
 *
 * Where the host is: EMPATHICA_CHATBOT_HOST_DIR if set, otherwise four levels
 * up, which is the host's root when the bundle is installed by Composer at
 * vendor/pixiekat/empathica-chatbot/.
 *
 * The bundle's Tests\ namespace is registered here as well, because the host's
 * autoloader only knows the bundle's src/ (its autoload-dev isn't installed
 * as a dependency).
 */

$hostDir = getenv('EMPATHICA_CHATBOT_HOST_DIR') ?: dirname(__DIR__, 4);

if (!is_file($hostDir . '/vendor/autoload.php')) {
  fwrite(STDERR, "Cannot find the host application at $hostDir. Set EMPATHICA_CHATBOT_HOST_DIR to its root.\n");
  exit(1);
}

$loader = require $hostDir . '/vendor/autoload.php';
$loader->addPsr4('Pixiekat\\EmpathicaChatbot\\Tests\\', __DIR__ . '/');

if (is_file($hostDir . '/tests/bootstrap.php')) {
  require $hostDir . '/tests/bootstrap.php';
}
