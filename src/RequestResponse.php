<?php namespace CCVOnlinePayments\CraftCMS;

use CCVOnlinePayments\Omnipay\Message\Response\FetchTransactionResponse;
use craft\commerce\omnipay\base\RequestResponse as BaseRequestResponse;

class RequestResponse extends BaseRequestResponse
{
    public function getMessage(): string
    {
        if($this->response instanceof FetchTransactionResponse) {
            $data = $this->response->getData();
            if(isset($data['failureCode'])) {
                switch($data['failureCode']) {
                    case "expired":
                        return \Craft::t('commerce', 'The payment has expired.');
                    case "cancelled":
                        return \Craft::t('commerce', 'The payment was cancelled.');
                    case "unsupported_currency":
                        return \Craft::t('commerce', 'The currency is not supported by this payment method');
                    case "processing_error":
                    case "authentication_failed":
                    case "bad_credentials":
                        return \Craft::t('commerce', 'There was an error while processing your payment.');
                }
            }
        }

        return $this->response->getMessage();
    }
}
