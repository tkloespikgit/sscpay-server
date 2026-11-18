<?php
require_once DIR_SYSTEM . 'library/sscpay/bootstrap.php';

class ControllerExtensionPaymentSscpaypaypal extends Controller {
    public function index(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 3, 'paypal'))->index();
    }

    public function install(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 3, 'paypal'))->install();
    }

    public function uninstall(): void {
        // Keep payment references, deduplication state and settings for reconciliation and upgrades.
    }
}
