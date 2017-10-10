<?php

namespace App\Models;


use Nette;
use Predis\Client;
use Nette\Mail\IMailer;

class AsyncJobProcessor{
    use Nette\SmartObject;

    /** @var UserModel */
    private $userModel;

    /** @var Client*/
    private $redis;

    /** @var IMailer */
    private $mailer;

    private $redisParams;

    private $jobProcessors;

    public function __construct(array $redisParams, UserModel $userModel, IMailer $mailer){
        $this->userModel = $userModel;
        $this->mailer = $mailer;
        $this->redisParams = $redisParams;
        $this->redis = new Client($redisParams['uri']);

        $this->jobProcessors = [
            'stackletter.user.download' => [$this, 'processUserDownload'],
            'stackletter.mail.welcome' => [$this, 'processWelcomeMail'],
        ];
    }


    public function run(){
        while(1){
            list($_, $data) = $this->redis->brpop($this->redisParams['job_queue'], 0);
            $job = json_decode($data, true);
            if(!isset($job['job'])){
                continue;
            }
            $this->processJob($job['job'], $job['params'] ?? []);
        }
    }


    public function processJob($job, $params){
        if(isset($this->jobProcessors[$job])){
            $this->log("Processing job %s", $job);
            return call_user_func($this->jobProcessors[$job], $params);
        } else{
            return false;
        }
    }

    public function processUserDownload($p){
        $account_id = $p['account_id'];
        $sites = $p['sites'];
        $access_token = $this->userModel->getToken($account_id);

        $this->userModel->createUsers($account_id, $sites, $access_token);
    }

    public function processWelcomeMail($p){
        $account = $this->userModel->get($p['account_id']);

        $mail = new Nette\Mail\Message();
        $mail->setFrom('stackletter@smasty.net')
             ->addTo($account->email)
             ->setSubject('Testing Sendgrid')
             ->setBody('Hello, this is a test of Sendgrid');

        $this->mailer->send($mail);
    }

    private function log($msg){
        $s = call_user_func_array('sprintf', func_get_args()) . "\n";
        echo $s;
    }

}
