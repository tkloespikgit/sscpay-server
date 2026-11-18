<?php
namespace Opencart\Catalog\Model\Extension\Sscpay\Payment;
require_once DIR_EXTENSION . 'sscpay/system/library/sscpay/bootstrap.php';

class Sscpaycreditcard extends \Opencart\System\Engine\Model {
    public function getMethod($address, $total = 0): array {
        return (new \Sscpay\OpenCart\Gateway($this->registry, 4, 'creditcard'))->method((array)$address, false);
    }

    public function getMethods(array $address = []): array {
        return (new \Sscpay\OpenCart\Gateway($this->registry, 4, 'creditcard'))->method($address, true);
    }
}
