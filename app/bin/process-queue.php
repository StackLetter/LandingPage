<?php

use App\Models\AsyncJobProcessor;
use Nette\DI\Container;
use Tracy\Debugger;

/** @var Container $container */
$container = require __DIR__ . '../bootstrap.php';

/** @var AsyncJobProcessor $processor */
$processor = $container->getByType(AsyncJobProcessor::class);

$processor->run();
