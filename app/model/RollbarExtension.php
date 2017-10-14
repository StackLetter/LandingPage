<?php

/**
 * @license    New BSD License
 * @link       https://github.com/nextras/tracy-monolog-adapter
 */

namespace App\Models;


use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\PsrHandler;
use Monolog\Logger as MonologLogger;
use Nette\DI\CompilerExtension;
use Nette\DI\Statement;
use Nette\PhpGenerator\ClassType;
use Nextras\TracyMonologAdapter\Logger as NextrasLogger;
use Nextras\TracyMonologAdapter\Processors\TracyExceptionProcessor;
use Rollbar\Rollbar;
use Tracy\Debugger;
use Tracy\Helpers;

class RollbarExtension extends CompilerExtension{

    public function loadConfiguration(){
        $builder = $this->getContainerBuilder();
        $logDir = isset($builder->parameters['logDir'])
            ? Helpers::expand('%logDir%', $builder->parameters)
            : Debugger::$logDirectory;

        $config = $this->getConfig();

        $builder->addDefinition($this->prefix('filehandler'))
                ->setType(RotatingFileHandler::class)
                ->setArguments([$logDir . '/nette.log'])
                ->setAutowired(false);

        $builder->addDefinition($this->prefix('handler'))
                ->setType(PsrHandler::class)
                ->setFactory(static::class . '::createHandler', [$config])
                ->setAutowired(false);

        $builder->addDefinition($this->prefix('tracyExceptionProcessor'))
                ->setType(TracyExceptionProcessor::class)
                ->setArguments([$logDir, '@Tracy\BlueScreen'])
                ->setAutowired(false);

        $monologLogger = $builder->addDefinition($this->prefix('monologLogger'))
                                 ->setType(MonologLogger::class)
                                 ->setArguments(['nette'])
                                 ->addSetup('pushHandler', ['@' . $this->prefix('filehandler')])
                                 ->addSetup('pushHandler', ['@' . $this->prefix('handler')])
                                 ->addSetup('pushProcessor', ['@' . $this->prefix('tracyExceptionProcessor')])
                                 ->setAutowired(false);

        $builder->addDefinition($this->prefix('tracyLogger'))
                ->setType(NextrasLogger::class)
                ->setArguments([$monologLogger]);

        if($builder->hasDefinition('tracy.logger')){
            $builder->getDefinition('tracy.logger')->setAutowired(false);
        }
    }

    public function afterCompile(ClassType $class){
        $initialize = $class->getMethod('initialize');
        $initialize->addBody('\Tracy\Debugger::setLogger($this->getByType(\Tracy\ILogger::class));');
    }

    public static function createHandler($config){
        Rollbar::init($config);
        return new PsrHandler(Rollbar::logger());
    }
}
