<?php namespace CCVOnlinePayments\CraftCMS;

use craft\commerce\models\payments\BasePaymentForm;

class PaymentFormModel extends BasePaymentForm {

    public $paymentMethod;
    public $issuer;
}
