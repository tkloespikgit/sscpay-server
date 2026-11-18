<?php
require_once DIR_SYSTEM . 'library/sscpay/bootstrap.php';

class ModelExtensionPaymentSscpaypaypal extends Model {
    public function getMethod($address, $total = 0): array {
        return (new \Sscpay\OpenCart\Gateway($this->registry, 3, 'paypal'))->method((array)$address, false);
    }
}
