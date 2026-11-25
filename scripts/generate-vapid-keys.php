<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "Falta vendor/autoload.php. Ejecuta composer install primero.\n");
    exit(1);
}
require $autoload;

$keys = \Minishlink\WebPush\VAPID::createVapidKeys();
echo "Copia estos valores como variables privadas del servidor y no los publiques en Git:\n";
echo 'PUSH_VAPID_PUBLIC_KEY=' . $keys['publicKey'] . PHP_EOL;
echo 'PUSH_VAPID_PRIVATE_KEY=' . $keys['privateKey'] . PHP_EOL;
