<?php

namespace App\Presenters;

use Nette\Application\Responses\TextResponse;
use Nette\Application\UI;
use Nette\Mail;


class WebhookPresenter extends UI\Presenter{

    /**
     * Webhook handling for inbound mail using SendGrid.
     */
    public function actionMail(){
        $post = $this->request->post;
        $mailParams = $this->context->parameters['mail'];

        if(!isset($post['envelope']['to']) || strpos($post['envelope']['to'][0], $mailParams['sender']) === false){
            $this->sendResponse(new TextResponse(''));
        }
        $msg = new Mail\Message;
        $msg->setSubject($post['subject'] ?? '[no subject]')
            ->setFrom($post['from']);
        foreach($mailParams['receivers'] as $addr){
            $msg->addTo($addr);
        }
        if(isset($post['html'])){
            $msg->setHtmlBody($post['html']);
        } else{
            $msg->setBody($post['text']);
        }
        $msg->setHeader('X-Sender-IP', $post['sender_ip'] ?? NULL)
            ->setHeader('X-Spam-Report', $post['spam_report'] ?? NULL)
            ->setHeader('X-Spam-Score', $post['spam_score'] ?? NULL)
            ->setHeader('X-DKIM-Result', $post['dkim'] ?? NULL)
            ->setHeader('X-SPF-Result', $post['SPF'] ?? NULL)
            ->setHeader('X-Attachments', $post['attachments'] ?? NULL);

        $this->context->getByType(Mail\IMailer::class)->send($msg);

        $this->sendResponse(new TextResponse(''));
    }

}
