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

    public function getAccount($user_id){
        return $this->db->select('accounts.*', 'users')
            ->leftJoin('accounts', ':accounts.id = :users.account_id')
            ->where(':users.id = %i', $user_id)
            ->where('users.account_id IS NOT NULL')
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


    public function unsubscribe($id, $code){
        $account = $this->getAccount($id);
        if(!$account){
            return false;
        }

        if($code !== $this->generateCode($id, $account['email'])){
            return false;
        }

        $query = $this->db->update('users', ['account_id' => NULL])->where('id', $id)->run();
        if(!$query){
            return false;
        }

        return [
            'mail' => $account['email'],
            'site' => $this->getUserSite($id),
        ];
    }


    public function resubscribe($id, $account_id, $code){
        $account = $this->db->select('accounts')->where('id', $account_id)->fetch();
        if(!$account){
            return false;
        }

        if($code !== $this->generateCode($id, $account['email'])){
            return false;
        }

        $query = $this->db->update('users', ['account_id' => NULL])->where('id', $id)->run();
        if(!$query){
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
