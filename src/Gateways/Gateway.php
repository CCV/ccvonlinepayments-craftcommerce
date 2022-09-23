<?php namespace CCVOnlinePayments\CraftCMS\Gateways;


use CCVOnlinePayments\CraftCMS\PaymentFormModel;
use CCVOnlinePayments\Omnipay\Message\Request\FetchTransactionRequest;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\Transaction;
use craft\commerce\omnipay\base\OffsiteGateway;
use CCVOnlinePayments\CraftCMS\RequestResponse;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\web\Response as WebResponse;
use craft\web\View;
use Omnipay\Common\AbstractGateway;
use Omnipay\Common\CreditCard;
use Omnipay\Common\ItemBag;
use Omnipay\Common\Message\ResponseInterface;
use yii\log\Logger;

class Gateway extends OffsiteGateway {

    /** @var string */
    public $apiKey;

    /** @var bool */
    public $methodEnabledIdeal;

    /** @var bool */
    public $methodEnabledCard_bcmc;

    /** @var bool */
    public $methodEnabledCard_maestro;

    /** @var bool */
    public $methodEnabledCard_mastercard;

    /** @var bool */
    public $methodEnabledCard_visa;

    /** @var bool */
    public $methodEnabledPaypal;

    /** @var bool */
    public $methodEnabledCard_amex;

    /** @var bool */
    public $methodEnabledSofort;

    /** @var bool */
    public $methodEnabledGiropay;

    /** @var bool */
    public $methodEnabledBanktransfer;

    public function populateRequest(array &$request, BasePaymentForm $paymentForm = null) : void
    {
        /** @var PaymentFormModel $paymentForm */
        if($paymentForm) {
            if($paymentForm->paymentMethod) {
                foreach($this->getPaymentMethods() as $pm) {
                    if($pm['id'] === $paymentForm->paymentMethod) {
                        $request['paymentMethod']   = $pm['apiMethod'];
                        $request['brand']           = $pm['apiBrand'];
                    }
                }
            }
            if($paymentForm->issuer) {
                $request['issuer'] = $paymentForm->issuer;
            }
        }

        $language = "eng";
        switch(\Craft::$app->locale->getLanguageID()) {
            case "nl":  $language = "nld"; break;
            case "de":  $language = "deu"; break;
            case "fr":  $language = "fra"; break;
        }

        $request['language']    = $language;
        $request['orderNumber'] = $request['description'];

        $request['browserAcceptHeaders']    = \Craft::$app->request->headers->get('Accept',"");
        $request['browserLanguage']         = \Craft::$app->request->headers->get('Accept-Language',"");
        $request['browserIpAddress']        = \Craft::$app->request->getRemoteIP();
        $request['browserUserAgent']        = \Craft::$app->request->headers->get('User-Agent',"");;
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        return parent::completePurchase($transaction);
    }

    public static function displayName(): string
    {
        return \Craft::t('commerce', 'CCV Online Payments');
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function getTransactionHashFromWebhook() : ?string
    {
        return \Craft::$app->getRequest()->getParam('commerceTransactionHash');
    }

    protected function createPaymentRequest(Transaction $transaction, ?CreditCard $card = null, ?ItemBag $itemBag = null): array {
        $paymentRequest = parent::createPaymentRequest($transaction, $card, $itemBag);
        $paymentRequest['transactionReference'] = $transaction->reference;
        return $paymentRequest;
    }

    public function processWebHook(): WebResponse
    {
        $response = \Craft::$app->getResponse();

        $transactionHash = $this->getTransactionHashFromWebhook();
        $transaction = Commerce::getInstance()->getTransactions()->getTransactionByHash($transactionHash);

        if (!$transaction) {
            $response->statusCode = 500;
            $response->data = 'Transaction with the hash “'.$transactionHash.'“ not found.';
            return $response;
        }

        // Check to see if a successful purchase child transaction already exist and skip out early if they do
        $successfulPurchaseChildTransaction = TransactionRecord::find()->where([
            'parentId'  => $transaction->id,
            'status'    => TransactionRecord::STATUS_SUCCESS,
            'type'      => TransactionRecord::TYPE_PURCHASE,
        ])->count();

        if ($successfulPurchaseChildTransaction) {
            $response->statusCode = 500;
            $response->data = 'Child transaction for “'.$transactionHash.'“ already exists.';
            return $response;
        }

        $gateway = $this->createGateway();
        /** @var FetchTransactionRequest $request */
        $request = $gateway->fetchTransaction(['transactionReference' => $transaction->reference]);
        $res = $request->send();

        if (!$res->isSuccessful()) {
            $response->statusCode = 500;
            $response->data = 'Request was unsuccessful.';
            return $response;
        }

        $childTransaction = Commerce::getInstance()->getTransactions()->createTransaction(null, $transaction);
        $childTransaction->type = $transaction->type;

        if ($res->isPaid()) {
            $childTransaction->status = TransactionRecord::STATUS_SUCCESS;
        } else if ($res->isExpired()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else if ($res->isCancelled()) {
            $childTransaction->status = TransactionRecord::STATUS_FAILED;
        } else {
            $response->data = 'ok';
            return $response;
        }

        $childTransaction->response     = $res->getData();
        $childTransaction->code         = $res->getTransactionId();
        $childTransaction->reference    = $res->getTransactionReference();
        $childTransaction->message      = $res->getMessage();
        Commerce::getInstance()->getTransactions()->saveTransaction($childTransaction);

        $response->data = 'ok';
        return $response;
    }

    public function getSettingsHtml() : ?string
    {
        return \Craft::$app->getView()->renderTemplate('ccvonlinepayments/settings', ['gateway' => $this]);
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new PaymentFormModel();
    }

    public function getPaymentFormHtml(array $params) : ?string
    {
        $params = array_merge([
            "gateway"           => $this,
            "paymentFormModel"  => $this->getPaymentFormModel(),
            "paymentMethods"    => $this->getPaymentMethods(),
        ], $params);

        $view = \Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        $html = $view->renderTemplate('ccvonlinepayments/paymentForm', $params);
        $view->setTemplateMode($previousMode);

        return $html;
    }

    public function getPaymentMethods() {
        $currency = \craft\commerce\Plugin::getInstance()->getCarts()->getCart()->paymentCurrency;
        if(!in_array($currency,["EUR", "CHF", "GBP"])) {
            return [];
        }

        // Get available payment methods from api
        $paymentMethods = \Craft::$app->getCache()->getOrSet("CCVONLINEPAYMENTS_METHOD", function() {
            $paymentMethodRequest = $this->createGateway()->fetchMethods();
            return $paymentMethodRequest->sendData($paymentMethodRequest->getData())->getPaymentMethods();
        }, 60*60);

        foreach($paymentMethods as $pmKey => &$paymentMethod) {
            $enabledProperty = "methodEnabled".ucfirst($paymentMethod['id']);
            if(!isset($this->$enabledProperty) || !$this->$enabledProperty) {
                unset($paymentMethods[$pmKey]);
                continue;
            }

            $paymentMethod['name'] = \Craft::t('commerce', $paymentMethod['name']);

            if(isset($paymentMethod['issuers'])) {
                foreach($paymentMethod['issuers'] as &$issuer) {
                    $issuer['name'] = \Craft::t('commerce', $issuer['name']);
                }
                unset($issuer);
            }
        }

        $billingAddress = \craft\commerce\Plugin::getInstance()->getCarts()->getCart()->billingAddress;
        if(method_exists($billingAddress, "getCountryCode")) {
            $countryCode = $billingAddress->getCountryCode();
        }elseif(method_exists($billingAddress, "getCountryIso")) {
            $countryCode  = $billingAddress->getCountryIso();
        }else{
            throw new \Exception("Unsupported address object");
        }
        return $this->sortMethods($paymentMethods, $countryCode);
    }

    private function sortMethods($paymentMethods, $country) {
        $sortedMethodIds = [
            "ideal",
            "card_bcmc",
            "card_maestro",
            "card_mastercard",
            "card_visa",
            "paypal",
            "card_amex",
            "sofort",
            "giropay",
            "banktransfer"
        ];

        if(strtoupper($country) === "BE") {
            $sortedMethodIds[0] = "card_bcmc";
            $sortedMethodIds[1] = "ideal";
        }

        $sortedMethodIds = array_flip($sortedMethodIds);

        usort($paymentMethods, function($a, $b) use($sortedMethodIds){
            $aOrder = $sortedMethodIds[$a['id']] ?? 999;
            $bOrder = $sortedMethodIds[$b['id']] ?? 999;

            if($aOrder === $bOrder) {
                return strcmp($a['id'], $b['id']);
            }else{
                return $aOrder <=> $bOrder;
            }
        });

        return $paymentMethods;
    }

    /**
     * @return \CCVOnlinePayments\Omnipay\Gateway
     */
    protected function createGateway(): AbstractGateway
    {
        /** @var \CCVOnlinePayments\Omnipay\Gateway $gateway */
        $gateway = static::createOmnipayGateway($this->getGatewayClassName());
        $gateway->setApiKey(\Craft::parseEnv($this->apiKey));

        $ccvOnlinePaymentsPlugin = \Craft::$app->getPlugins()->getPluginInfo('ccvonlinepayments');
        if($ccvOnlinePaymentsPlugin) {
            $gateway->setMetadataValue("CCVOnlinePayments", $ccvOnlinePaymentsPlugin['version']);
        }

        $commercePluginInfo = \Craft::$app->getPlugins()->getPluginInfo('commerce');
        if ($commercePluginInfo) {
            $gateway->setMetadataValue('CraftCommerce', $commercePluginInfo['version']);
        }

        return $gateway;
    }

    /**
     * @inheritdoc
     */
    public function getPaymentTypeOptions(): array
    {
        return [
            'purchase' => \Craft::t('commerce', 'Purchase (Authorize and Capture Immediately)')
        ];
    }

    protected function getGatewayClassName() : ?string
    {
        return '\\'. \CCVOnlinePayments\Omnipay\Gateway::class;
    }

    protected function prepareResponse(ResponseInterface $response, Transaction $transaction): RequestResponseInterface
    {
        $level = $response->isSuccessful() ? Logger::LEVEL_INFO : Logger::LEVEL_ERROR;
        \Craft::getLogger()->log(
            "CCVOnlinePayments request:".json_encode($response->getRequest()->getData())." response:".json_encode($response->getData()),
            $level,
            "ccvonlinepayments"
        );

        return new RequestResponse($response, $transaction);
    }

}
