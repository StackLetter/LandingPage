<?php

namespace App\Models;


use Nette;
use Predis\Client;

class AsyncJobProcessor{
    use Nette\SmartObject;

    /**
     * @var UserModel
     * @inject
     */
    public $userModel;

    /**
     * @var Client
     */
    private $redis;

    private $redisParams;

    private $jobProcessors;

    public function __construct(array $redisParams){
        $this->redisParams = $redisParams;
        $this->redis = new Client($redisParams['uri']);

        $this->jobProcessors = [
            'stackletter.user.download' => [$this, 'processUserDownload'],
            'stackletter.mail.welcome' => [$this, 'processWelcomeMail'],
        ];
    }


    public function run(){
        while(1){
            $data = $this->redis->brpop($this->redisParams['job_queue']);
            $job = json_decode($data, true);
            if(!isset($job['job'])){
                continue;
            }
            $this->processJob($job['job'], $job['params'] ?? []);
        }
    }


    public function processJob($job, $params){
        if(isset($this->jobProcessors[$job])){
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
        // TODO
    }

}
