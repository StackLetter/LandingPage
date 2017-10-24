<?php

require __DIR__ . '/../vendor/autoload.php';

define('APP_DIR', __DIR__);

$configurator = new Nette\Configurator;

$configurator->setDebugMode('147.175.149.71'); // enable for your remote IP
$configurator->enableTracy(__DIR__ . '/log');

$configurator->setTimeZone('UTC');
$configurator->setTempDirectory(__DIR__ . '/temp');

$configurator->createRobotLoader()
    ->addDirectory(__DIR__)
    ->register();

$configurator->addConfig(__DIR__ . '/config/config.neon');
$configurator->addConfig(__DIR__ . '/config/config.local.neon');

$container = $configurator->createContainer();

return $container;
