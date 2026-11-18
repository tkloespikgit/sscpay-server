<?php
require_once DIR_SYSTEM . 'library/sscpay/bootstrap.php';

class ControllerExtensionPaymentSscpaypaypal extends Controller {
    public function index(): string {
        $result = (new \Sscpay\OpenCart\Gateway($this->registry, 3, 'paypal'))->index();
        if (in_array($this->request->get['sscpay_action'] ?? '', ['return', 'cancel'], true)) {
            $this->response->addHeader('Cache-Control: no-store');
            $this->response->addHeader('Referrer-Policy: no-referrer');
            $this->response->setOutput($result);
        }
        return $result;
    }
}
