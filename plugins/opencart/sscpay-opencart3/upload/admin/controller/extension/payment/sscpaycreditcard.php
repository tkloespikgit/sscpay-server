<?php
require_once DIR_SYSTEM . 'library/sscpay/bootstrap.php';

class ControllerExtensionPaymentSscpaycreditcard extends Controller {
    public function index(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 3, 'creditcard'))->index();
    }

    public function install(): void {
        (new \Sscpay\OpenCart\Admin($this->registry, 3, 'creditcard'))->install();
    }

    public function uninstall(): void {
        // Keep payment references, deduplication state and settings for reconciliation and upgrades.
    }
}
