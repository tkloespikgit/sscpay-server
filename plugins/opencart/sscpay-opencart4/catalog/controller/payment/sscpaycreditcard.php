<?php
namespace Opencart\Catalog\Controller\Extension\Sscpay\Payment;
require_once DIR_EXTENSION . 'sscpay/system/library/sscpay/bootstrap.php';

class Sscpaycreditcard extends \Opencart\System\Engine\Controller {
    public function index(): string {
        $result = (new \Sscpay\OpenCart\Gateway($this->registry, 4, 'creditcard'))->index();
        if (in_array($this->request->get['sscpay_action'] ?? '', ['return', 'cancel'], true)) {
            $this->response->addHeader('Cache-Control: no-store');
            $this->response->addHeader('Referrer-Policy: no-referrer');
            $this->response->setOutput($result);
        }
        return $result;
    }
}
