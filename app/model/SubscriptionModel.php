<?php

namespace App\Models;


use Neevo\Manager;
use Nette;
use Tracy\Debugger;

class SubscriptionModel{
    use Nette\SmartObject;

    /** @var Manager */
    private $db;

    private $config;

    public function __construct(array $config, Manager $db){
        $this->db = $db;
        $this->config = $config;
    }

    private function getAccount($user_id, $resubscribe = false){
        return $this->db->select('accounts.*', 'users')
            ->leftJoin('accounts', ':accounts.id = :users.account_id')
            ->where(':users.id = %i', $user_id)
            ->if(!$resubscribe)
                ->where('users.account_id IS NOT NULL')
            ->end()
            ->fetch();
    }

    public function getUserSite($user_id){
        return $this->db->select('sites.name', 'users')
            ->leftJoin('sites', ':sites.id = :users.site_id')
            ->where(':users.id = %i', $user_id)
            ->fetchSingle();
    }

    private function generateCode($user_id, $mail){
        return hash_hmac($this->config['algo'], "$user_id/$mail", $this->config['secret_key']);
    }


    public function updateSubscription($id, $code, $resubscribe = false){
        $account = $this->getAccount($id, $resubscribe);
        if(!$account){
            Debugger::barDump('No account');
            return false;
        }

        if($code !== $this->generateCode($id, $account['email'])){
            Debugger::barDump("Code mismatch");
            Debugger::barDump($id, 'id');
            Debugger::barDump($account['email'], 'mail');
            Debugger::barDump($code, 'provided code');
            Debugger::barDump($this->generateCode($id, $account['email']), 'generated code');
            return false;
        }

        $query = $this->db->update('users', ['account_id' => $resubscribe ? $account['id'] : NULL])->where('id', $id)->run();
        if(!$query){
            Debugger::barDump("Query UDPATE failed");
            return false;
        }

        return [
            'mail' => $account['email'],
            'site' => $this->getUserSite($id),
        ];
    }


    public function getUnsubscribeCode($user_id){
        $account = $this->getAccount($user_id);
        if(!$account){
            return false;
        }
        return $this->generateCode($user_id, $account['email']);
    }

}
