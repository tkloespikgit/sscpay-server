<?php
namespace Opencart\Admin\Controller\Extension\Sscpay\Payment;
require_once DIR_EXTENSION . 'sscpay/system/library/sscpay/bootstrap.php';

class Sscpaypaypal extends \Opencart\System\Engine\Controller {
    public function index(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 4, 'paypal'))->index();
    }

    public function install(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 4, 'paypal'))->install();
    }

    public function uninstall(): void {
        // Keep payment references, deduplication state and settings for reconciliation and upgrades.
    }
}
