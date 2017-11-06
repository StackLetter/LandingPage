<?php

namespace App\Models;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Neevo\Literal;
use Neevo\Manager;
use Neevo\NeevoException;
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
        return $this->db->select('accounts')->where('id', $id)->fetch();
    }

    public function getByExternalId($external_id){
        if($external_id === null){
            return false;
        }
        return $this->db->select('accounts')->where('external_id', $external_id)->fetch();
    }

    public function getByToken($token){
        return $this->db->select('accounts')->where('token', $token)->fetch();
    }


    public function getToken($id){
        return $this->db->select('token', 'accounts')->where('id', $id)->fetchSingle();
    }


    public function getSubscribedSites($account_id){
        return $this->db->select('s.name, users.id AS user_id', 'users')
            ->leftJoin('sites s', 'users.site_id = s.id')
            ->where('users.account_id = %i', $account_id);
    }


    public function createAccount($mail, $token, $frequency){
        return $this->db->insert('accounts', [
            'email' => $mail,
            'token' => $token,
            'external_id' => $this->fetchExternalId($token),
            'frequency' => $frequency,
            'created_at' => new Literal('NOW()'),
            'updated_at' => new Literal('NOW()'),
            'available_token' => true,
        ])->insertId();
    }


    public function updateAccount($id, $values){
        $values['updated_at'] = new Literal('NOW()');
        return $this->db->update('accounts', $values)->where('id', $id)->run();
    }


    public function retrieveUserSites($token){
        $sites = $this->fetchUserSites($token);
        return !empty($sites)
            ? $this->db->select('sites')
                ->where('enabled', true)
                ->where('name', $sites)
                ->fetchPairs('api', 'name')
            : false;
    }


    public function fetchExternalId($token){
        try{
            $res = $this->http->get("/access-tokens/$token", ['query' => ['key' => $this->apiParams['key']]]);
            $json = json_decode($res->getBody(), true);
            return $json['items'][0]['account_id'] ?? null;
        } catch(ClientException $e){
            return null;
        }
    }


    private function fetchUserSites($token, $page = 1){
        try{
            $res = $this->http->get('me/associated', ['query' => [
                'access_token' => $token,
                'key' => $this->apiParams['key'],
                'filter' => '!w*vbPYOwJWIjXz4IU2',
                'pagesize' => 100,
                'page' => $page,
            ]]);

            $json = json_decode($res->getBody(), true);
            $sites = [];
            foreach($json['items'] as $site){
                $sites[] = $site['site_name'];
            }

            if($json['has_more'] === true){
                return array_merge($sites, $this->fetchUserSites($token, $page + 1));
            }

            return $sites;

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
        $site_ids = $this->db->select('api, id', 'sites')->fetchPairs('api', 'id');

        foreach($sites as $site){
            $data = $this->getSiteUser($site, $token);
            if(!$data){
                continue;
            }

            $this->db->begin();
            try{

                if($this->db->select('users')->where('external_id', $data['user_id'])->and('site_id', $site_ids[$site])->fetch()){
                    $this->db->update('users', [
                        'account_id' => $account_id,
                        'updated_at' => new Literal('NOW()')
                    ])
                        ->where('external_id', $data['user_id'])
                        ->and('site_id', $site_ids[$site])
                        ->run();
                    $this->db->commit();
                    $this->queueSidekiqUserDownload($data['user_id'], $site_ids[$site]);
                    continue;
                }
                $this->db->insert('users', [
                    'account_id' => $account_id,
                    'external_id' => $data['user_id'],
                    'site_id' => $site_ids[$site],
                    'age' => $data['age'] ?? null,
                    'reputation' => $data['reputation'],
                    'accept_rate' => $data['accept_rate'] ?? null,
                    'reputation_change_month' => $data['reputation_change_month'],
                    'reputation_change_year' => $data['reputation_change_year'],
                    'reputation_change_week' => $data['reputation_change_week'],
                    'creation_date' => DateTime::from($data['creation_date']),
                    'last_access_date' => DateTime::from($data['last_access_date']),
                    'display_name' => html_entity_decode($data['display_name'], ENT_HTML5, 'UTF-8'),
                    'user_type' => $data['user_type'],
                    'website_url' => $data['website_url'] ?? null,
                    'location' => isset($data['location']) ? html_entity_decode($data['location'], ENT_HTML5, 'UTF-8') : null,
                    'is_employee' => (bool) $data['is_employee'],
                    'created_at' => new Literal('NOW()'),
                    'updated_at' => new Literal('NOW()')
                ])->run();
            } catch(NeevoException $e){
                Debugger::log($e);
                $this->db->rollback();
            }
            $this->queueSidekiqUserDownload($data['user_id'], $site_ids[$site]);
            $this->db->commit();
        }
    }

    private function queueSidekiqUserDownload($external_id, $site_id){
        $sidekiq = new \SidekiqJob\Client($this->redis);
        $sidekiq->push('UserDataParserJob', [$external_id, $site_id], true, 'new_user');
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
