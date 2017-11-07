<?php

namespace App\Presenters;

use App\Models\SubscriptionModel;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI;
use Nette\Mail;
use Tracy\Debugger;


class SubscriptionPresenter extends UI\Presenter{

    /**
     * @var SubscriptionModel
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
        $this->session->getIterator(); // force start()
    }


    public function actionUnsubscribe($id){
        if($this->request->getMethod() == 'POST'){
            return;
        }
        $code = $this->getParameter('code');
        $this->template->manage = $this->getParameter('manage');

        $account = $this->model->getAccount($id);
        if($account){
            $this->session->account_id = $account['id'];
        }

        $result = $this->model->unsubscribe($id, $code);
        if(!$result){
            $this->redirect('unsubscribeerror');
            return;
        }

        $this->session->site = $result['site'];

        $this->template->mail = $result['mail'];
        $this->template->site = $result['site'];
        $this->template->resubscribeLink = $this->link('//resubscribe', [
            'id' => $id,
            'code' => $code,
            'manage' => $this->getParameter('manage'),
        ]);
    }


    public function actionResubscribe($id){
        $code = $this->getParameter('code');
        $this->template->manage = $this->getParameter('manage');

        $result = $this->model->resubscribe($id, $this->session->account_id, $code);
        if(!$result){
            $this->redirect('resubscribeerror');
            return;
        }

        $this->template->mail = $result['mail'];
        $this->template->site = $result['site'];
    }


    public function actionGeneratelink($id){
        if($this->getParameter('token') !== $this->context->parameters['unsubscribe']['api_token']){
            $this->sendResponse(new JsonResponse([
                'status' => 'error',
                'reason' => 'unauthenticated'
            ]));
        }

        $code = $this->model->getUnsubscribeCode($id);
        if(!$code){
            $this->sendResponse(new JsonResponse([
                'status' => 'error',
                'reason' => 'unknown_id'
            ]));
        }

        $link = $this->link('//unsubscribe', ['id' => $id, 'code' => $code]);
        $this->sendResponse(new JsonResponse([
            'status' => 'success',
            'link' => $link
        ]));
    }


    protected function createComponentFeedbackForm(){
        $form = new UI\Form;

        $form->addProtection();
        $form->addCheckboxList('reason', 'Reason', [
            'relevance' => 'The newsletter content is not relevant',
            'expectation' => 'I expected more from the newsletter',
            'stop' => 'I no longer wish to receive the newsletter',
        ]);
        $form->addTextArea('body', 'Other');
        $form->addSubmit('send', 'Send');
        $form->onSuccess[] = [$this, 'feedbackFormSubmitted'];

        return $form;
    }

    public function feedbackFormSubmitted(UI\Form $form){
        $values = (array) $form->values;

        $message = sprintf(
            "Account ID: %s\nSite: %s\nReasons: %s\n\nText:\n%s\n",
            $this->session->account_id ?? 'unknown',
            $this->session->site ?? 'unknown',
            join(', ', $values['reason']),
            $values['body']
        );

        $mail = new Mail\Message;
        $mail->setFrom('info@stackletter.com')
             ->setSubject('[StackLetter] Unsubscribe feedback')
             ->setBody($message)
             ->setHeader('X-Mailer', 'StackLetter');
        foreach($this->context->getParameters()['mail']['receivers'] as $addr){
            $mail->addTo($addr);
        }

        $this->context->getByType(Mail\IMailer::class)->send($mail);

        $this->flashMessage('Thank you for your feedback.', 'success');
        $this->redirect('Homepage:default');
    }

}
