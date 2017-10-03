<?php

namespace App\Presenters;

use GuzzleHttp\Client;
use Nette\Application\UI;
use Nette\Http\Url;
use Tracy\Debugger;


/**
 * @property-read array $config
 */
class HomepagePresenter extends UI\Presenter{

    public function getConfig(){
        return $this->context->getParameters();
    }

    protected function createComponentSignUpForm(){
        $form = new UI\Form;

        $form->addEmail('mail', 'E-mail')->setRequired();
        $form->addMultiSelect('site', 'Stack Exchange site', $this->config['se_sites'])->setRequired();
        $form->addSubmit('send', 'Authorize & Sign up');
        $form->onSuccess[] = [$this, 'signUpFormSubmitted'];

        return $form;
    }

    public function signUpFormSubmitted(UI\Form $form){
        $values = (array) $form->values;

        // TODO check if email is in database

        $this->redirectUrl($this->constructOAuthUrl());
    }

    private function constructOAuthUrl(){
        $api = $this->config['se_api'];

        $url = new Url($api['base_url']);
        $url->setQueryParameter('client_id', $api['client_id'])
            ->setQueryParameter('scope', $api['scope'])
            ->setQueryParameter('redirect_uri', $this->link('//authorize'));
        return $url;
    }


    public function actionAuthorize($code){
        $api = $this->config['se_api'];
        $http = new Client();

        $res = $http->request('POST', $api['token_url'], [
            'form_params' => [
                'client_id' => $api['client_id'],
                'client_secret' => $api['client_secret'],
                'code' => $code,
                'redirect_uri' => $this->link('//authorize')
            ]
        ]);

        if($res->getStatusCode() != 200){
            $this->flashMessage('Could not authorize the application. Please try again later.', 'danger');
            $this->redirect('default#signup');
        }
        $data = json_decode($res->getBody(), true);
        Debugger::barDump($data);
    }

}
