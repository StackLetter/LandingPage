<?php

namespace App\Presenters;

use App\Models\UserModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Nette\Application\UI;
use Nette\Http\SessionSection;
use Nette\Http\Url;


/**
 * @property-read array $config
 */
class HomepagePresenter extends UI\Presenter{

    /**
     * @var UserModel
     * @inject
     */
    public $model;

    /**
     * @var SessionSection
     */
    private $session;

    public function startup(){
        parent::startup();
        $this->session = $this->getSession()->getSection(static::class);
    }

    public function getConfig(){
        return $this->context->getParameters();
    }


    private function constructOAuthUrl(){
        $api = $this->config['se_api'];

        $this->session->token = bin2hex(random_bytes(32));
        $this->session->setExpiration('10 minutes', 'token');

        $url = new Url($api['base_url']);
        $url->setQueryParameter('client_id', $api['client_id'])
            ->setQueryParameter('scope', $api['scope'])
            ->setQueryParameter('redirect_uri', $this->link('//authorize', ['token' => $this->session->token]));
        return $url;
    }


    public function handleAuthorize(){
        $this->redirectUrl($this->constructOAuthUrl());
    }


    public function actionAuthorize($token, $code){
        $showError = function (){
            $this->flashMessage('Could not authorize the application. Please try again later.', 'danger');
            $this->redirect('default#signup');
        };

        if(($this->session->token !== null && $token !== $this->session->token) || !$code){
            $this->redirect('default');
        }

        if($this->getParameter('error') !== null){
            $showError();
        }

        $api = $this->config['se_api'];
        $http = new Client();
        try{
            $res = $http->request('POST', $api['token_url'], [
                'form_params' => [
                    'client_id' => $api['client_id'],
                    'client_secret' => $api['client_secret'],
                    'code' => $code,
                    'redirect_uri' => $this->link('//authorize', ['token' => $this->session->token]),
                ],
            ]);
        } catch(ClientException $e){
            $showError();
        }

        $data = json_decode($res->getBody(), true);
        $this->session->access_token = $data['access_token'];

        $this->flashMessage('Sign up successful.', 'success');
        $this->redirect('signup');
    }


    public function actionSignup(){
        if(!isset($this->session->access_token)){
            $this->redirect('default');
        }
    }


    protected function createComponentSignUpForm(){
        $form = new UI\Form;

        $sites = $this->model->retrieveUserSites($this->session->access_token);

        $form->addEmail('mail', 'E-mail')->setRequired();
        $form->addMultiSelect('site', 'Stack Exchange sites', $sites)->setRequired();
        $form->addSubmit('send', 'Authorize & Sign up');
        $form->onSuccess[] = [$this, 'signUpFormSubmitted'];

        return $form;
    }

    public function signUpFormSubmitted(UI\Form $form){
        $values = (array)$form->values;

        // Check for existing e-mail
        if($this->model->getByEmail($values['mail']) !== false){
            $form->addError('The e-mail you entered is already in use.');
            return;
        }

        $account_id = $this->model->createAccount($values['mail'], $this->session->token);
        $this->model->createUsers($account_id, $values['sites'], $this->session->token);
    }


}
