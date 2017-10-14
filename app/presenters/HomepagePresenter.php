<?php

namespace App\Presenters;

use App\Models\UserModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Nette\Application\UI;
use Nette\Http\SessionSection;
use Nette\Http\Url;
use Nette\Mail;


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
    public $session;

    public function startup(){
        parent::startup();
        $this->session = $this->getSession()->getSection(static::class);
    }

    public function getConfig(){
        return $this->context->getParameters();
    }


    private function constructOAuthUrl(){
        $api = $this->config['se_api'];

        $this->session->csrf = bin2hex(random_bytes(32));
        $this->session->setExpiration('10 minutes', 'csrf');

        $url = new Url($api['base_url']);
        $url->setQueryParameter('client_id', $api['client_id'])
            ->setQueryParameter('scope', $api['scope'])
            ->setQueryParameter('redirect_uri', $this->link('//authorize', ['csrf' => $this->session->csrf]));
        return $url;
    }


    public function handleAuthorize(){
        $this->redirectUrl($this->constructOAuthUrl());
    }


    public function actionAuthorize($csrf, $code){
        $showError = function (){
            $this->flashMessage('Could not authorize the application. Please try again later.', 'danger');
            $this->redirect('default#signup');
        };

        if(($this->session->csrf !== null && $csrf !== $this->session->csrf) || !$code){
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
                    'redirect_uri' => $this->link('//authorize', ['csrf' => $this->session->csrf]),
                ],
            ]);
        } catch(ClientException $e){
            $showError();
        }

        $data = json_decode($res->getBody(), true);
        $this->session->access_token = $data['access_token'];

        $this->flashMessage('Stack Exchange authorization was successful.', 'success');
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
        $form->addRadioList('frequency', 'Newsletter frequency', ['d' => 'Daily', 'w' => 'Weekly'])
             ->setDefaultValue('d')->setRequired();
        $form->addSubmit('send', 'Authorize & Sign up');
        $form->onSuccess[] = [$this, 'signUpFormSubmitted'];

        return $form;
    }

    public function signUpFormSubmitted(UI\Form $form){
        $values = (array) $form->values;

        // Check for existing e-mail
        if($this->model->getByEmail($values['mail']) !== false){
            $form->addError('The e-mail you entered is already in use.');
            return;
        }

        $account_id = $this->model->createAccount($values['mail'], $this->session->access_token, $values['frequency']);
        $this->model->scheduleUsers($account_id, $values['site']);
        $this->model->scheduleWelcomeMail($account_id);

        $this->flashMessage('Thank you for signing up!', 'success');
        $this->session->registered = true;
        $this->redirect('default#signup');
    }


    protected function createComponentContactForm(){
        $form = new UI\Form;

        $form->addText('name', 'Name')->setRequired();
        $form->addEmail('mail', 'E-mail')->setRequired();
        $form->addTextArea('body', 'Message')->setRequired();
        $form->addSubmit('send', 'Send');
        $form->onSuccess[] = [$this, 'contactFormSubmitted'];

        return $form;
    }


    public function contactFormSubmitted(UI\Form $form){
        $values = (array) $form->values;

        $mail = new Mail\Message;
        $mail->setFrom($values['mail'], $values['name'])
             ->setSubject('[StackLetter] Contact')
             ->setBody($values['body']);
        foreach($this->config['contact'] as $addr){
            $mail->addTo($addr);
        }

        $this->context->getByType(Mail\IMailer::class)->send($mail);

        $this->flashMessage('Thank you for your message. We\'ll get back to you as soon as possible.', 'success');
        $this->redirect('this#contact');
    }

}
