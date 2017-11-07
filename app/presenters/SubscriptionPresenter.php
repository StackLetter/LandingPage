<?php

namespace App\Presenters;

use App\Models\SubscriptionModel;
use Nette\Application\Responses\JsonResponse;
use Nette\Application\UI;


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

}
