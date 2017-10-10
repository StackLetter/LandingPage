<?php

namespace App\Models;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Neevo\Literal;
use Neevo\Manager;
use Nette;
use Nette\Utils\DateTime;
use Tracy\Debugger;

class UserModel{
    use Nette\SmartObject;

    /** @var Manager */
    private $db;

    /** @var Client */
    private $http;

    private $apiParams;

    /** @var \Predis\Client */
    public $redis;

    public $redisParams;


    public function __construct(array $apiParams, array $redisParams, Manager $db){
        $this->apiParams = $apiParams;
        $this->redisParams = $redisParams;
        $this->db = $db;
        $this->http = new Client(['base_uri' => 'https://api.stackexchange.com/2.2/']);
        $this->redis = new \Predis\Client($this->redisParams['uri']);
    }


    public function beginTransaction($savepoint = null){
        return $this->db->begin($savepoint);
    }

    public function commitTransaction($savepoint = null){
        return $this->db->commit($savepoint);
    }

    public function rollbackTransaction($savepoint = null){
        return $this->db->rollback($savepoint);
    }


    public function getByEmail($email){
        return $this->db->select('accounts')->where('email', $email)->fetch();
    }


    public function get($id){
        return $this->db->select('accounts')->wherer('id', $id)->fetch();
    }


    public function getToken($id){
        return $this->db->select('token', 'accounts')->where('id', $id)->fetchSingle();
    }


    public function createAccount($mail, $token, $frequency){
        return $this->db->insert('accounts', [
            'email' => $mail,
            'token' => $token,
            'frequency' => $frequency
        ])->insertId();
    }


    public function retrieveUserSites($token){

        try{
            $res = $this->http->get('me/associated', ['query' => [
                'access_token' => $token,
                'key' => $this->apiParams['key']
            ]]);

            $sites = [];
            foreach(json_decode($res->getBody(), true)['items'] as $site){
                $sites[] = $site['site_name'];
            }
            if(empty($sites)){
                return [];
            }

            return $this->db->select('sites')
                ->where('enabled', true)
                ->where('name', $sites)
                ->fetchPairs('api', 'name');

        } catch(ClientException $e){
            return false;
        }
    }


    public function scheduleUsers($account_id, $sites){
        $this->redis->lpush($this->redisParams['job_queue'], json_encode([
            'job' => 'stackletter.user.download',
            'params' => [
                'account_id' => $account_id,
                'sites' => $sites
            ]
        ]));
    }


    public function scheduleWelcomeMail($account_id){
        $this->redis->lpush($this->redisParams['job_queue'], json_encode([
            'job' => 'stackletter.mail.welcome',
            'params' => ['account_id' => $account_id]
        ]));
    }


    public function createUsers($account_id, $sites, $token){
        foreach($sites as $site){
            $data = $this->getSiteUser($site, $token);
            if(!$data){
                continue;
            }
            Debugger::barDump($data);
            $this->db->insert('users',[
                'account_id' => $account_id,
                'external_id' => $data['user_id'],
                'age' => $data['age'] ?? null,
                'reputation' => $data['reputation'],
                'accept_rate' => $data['accept_rate'] ?? null,
                'reputation_change_month' => $data['reputation_change_month'],
                'reputation_change_year' => $data['reputation_change_year'],
                'reputation_change_week' => $data['reputation_change_week'],
                'creation_date' => DateTime::from($data['creation_date']),
                'last_access_date' => DateTime::from($data['last_access_date']),
                'display_name' => $data['display_name'],
                'user_type' => $data['user_type'],
                'website_url' => $data['website_url'] ?? null,
                'location' => $data['location'] ?? null,
                'is_employee' => (bool) $data['is_employee'],
                'created_at' => new Literal('NOW()'),
                'updated_at' => new Literal('NOW()'),
            ])->run();
        }
    }

    private function getSiteUser($site, $token){
        try{
            $res = $this->http->get('me', ['query' => [
                'access_token' => $token,
                'key' => $this->apiParams['key'],
                'site' => $site,
            ]]);

            return json_decode($res->getBody(), true)['items'][0];
        } catch(ClientException $e){
            return false;
        }
    }

}
