<?php

namespace App\Models;


use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Neevo\Manager;
use Nette;

class UserModel{
    use Nette\SmartObject;

    /**
     * @var Manager
     */
    private $db;

    /**
     * @var Client
     */
    private $http;

    private $apiParams;

    public function __construct(array $params, Manager $db){
        $this->apiParams = $params;
        $this->db = $db;
        $this->http = new Client(['base_uri' => 'https://api.stackexchange.com/2.2/']);
    }


    public function getByEmail($email){
        return $this->db->select('accounts')->where('email', $email)->fetch();
    }


    public function createAccount($mail, $token){
        return $this->db->insert('accounts', [
            'email' => $mail,
            'token' => $token
        ])->run();
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

}
