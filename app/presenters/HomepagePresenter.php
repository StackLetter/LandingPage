<?php

namespace App\Presenters;

use App\Models\SubscriptionModel;
use App\Models\UserModel;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use Nette\Application\UI;
use Nette\Http\SessionSection;
use Nette\Http\Url;
use Nette\Mail;
use Tracy\Debugger;


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
     * @var SubscriptionModel
     * @inject
     */
    public $subscriptionModel;

    /**
     * @var SessionSection
     */
    public $session;

    public function startup(){
        parent::startup();
        $this->session = $this->getSession()->getSection(static::class);
        $this->session->getIterator(); // force start()
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

        $external_id = $this->model->fetchExternalId($this->session->access_token);
        $account = $this->model->getByExternalId($external_id);
        if($account){
            $this->session->account = $account->toArray();
            $this->redirect('manage');
        }
    }


    protected function createComponentSignUpForm(){
        $form = new UI\Form;

        $sites = $this->model->retrieveUserSites($this->session->access_token);

        $form->addEmail('mail', 'E-mail')->setRequired();

        if($sites !== false){
            $form->addMultiSelect('site', 'Stack Exchange sites', $sites)->setRequired();
        }
        $form->addRadioList('frequency', 'Newsletter frequency', ['d' => 'Daily', 'w' => 'Weekly'])
             ->setDefaultValue('d')->setRequired();
        $form->addSubmit('send', 'Authorize & Sign up');
        $form->onSuccess[] = [$this, 'signUpFormSubmitted'];

        return $form;
    }

    public function signUpFormSubmitted(UI\Form $form){
        $values = (array) $form->values;

        // Check for existing e-mail
        if(isset($values['mail']) && $this->model->getByEmail($values['mail']) !== false){
            $form->addError('The e-mail you entered is already in use.');
            return;
        }

        if(isset($values['mail'])){
            $account_id = $this->model->createAccount($values['mail'], $this->session->access_token, $values['frequency']);
            $this->model->scheduleWelcomeMail($account_id);
        } else{
            $account_id = $this->session->account['id'];
            $this->model->updateAccount($account_id, ['frequency' => $values['frequency']]);
            $this->session->account = $this->model->get($account_id)->toArray();
        }
        if(isset($values['site'])){
            $this->model->scheduleUsers($account_id, $values['site'], $this->session->access_token);
        }

        if(isset($values['mail'])){
            $this->flashMessage('Thank you for signing up!', 'success');
            $this->session->account = $this->model->getByToken($this->session->access_token)->toArray();
            $this->redirect('default#signup');
        } else{
            $this->flashMessage('Subscriptions updated.', 'success');
            sleep(3); // wait a bit for async job
            $this->redirect('this');
        }
    }


    protected function createComponentContactForm(){
        $form = new UI\Form;

        $form->addProtection();
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
             ->setBody($values['body'])
             ->setHeader('X-Mailer', 'StackLetter');
        foreach($this->config['mail']['receivers'] as $addr){
            $mail->addTo($addr);
        }

        $this->context->getByType(Mail\IMailer::class)->send($mail);

        $this->flashMessage('Thank you for your message. We\'ll get back to you as soon as possible.', 'success');
        $this->redirect('this#contact');
    }


    public function actionManage(){
        if(!isset($this->session->account)){
            $this->redirect('default');
        }

        $sites = $this->model->getSubscribedSites($this->session->account['id']);
        $subscribed = [];
        foreach($sites as $site){
            $subscribed[] = [
                'name' => $site->name,
                'unsubscribe' => $this->link('//Subscription:unsubscribe', [
                    'id' => $site->user_id,
                    'code' => $this->subscriptionModel->getUnsubscribeCode($site->user_id),
                    'manage' => true
                ])
            ];
        }
        $this->template->subscribed = $subscribed;
    }


    protected function createComponentManageForm(){
        $form = $this->createComponentSignUpForm();

        unset($form['mail']);
        if(isset($form['site'])){
            $form['site']->setRequired(false);
        }
        $form['frequency']->setValue($this->session->account['frequency'] ?? null);

        return $form;
    }

    public function actionLogout(){
        unset($this->session->access_token);
        unset($this->session->account);
        $this->flashMessage('Logout successful.', 'success');
        $this->redirect('default');
    }

}
