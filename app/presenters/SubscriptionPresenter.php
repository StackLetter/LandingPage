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


    public function actionUnsubscribe($id){
        $code = $this->getParameter('code');

        $result = $this->model->unsubscribe($id, $code);
        if(!$result){
            $this->redirect('unsubscribeerror');
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
