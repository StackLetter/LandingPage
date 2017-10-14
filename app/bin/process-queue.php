#!/usr/bin/env php
<?php

use App\Models\AsyncJobProcessor;
use Nette\DI\Container;
use Tracy\Debugger;


/** @var Container $container */
$container = require __DIR__ . '/../bootstrap.php';

// Create PID file
file_put_contents($container->parameters['tempDir'] . '/process-queue.pid', getmypid() . "\n");

/** @var AsyncJobProcessor $processor */
$processor = $container->getByType(AsyncJobProcessor::class);

$processor->run();
