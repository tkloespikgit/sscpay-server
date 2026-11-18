<?php
require_once DIR_EXTENSION . 'sscpay/system/library/sscpay/bootstrap.php';
foreach (\Sscpay\OpenCart\Text::all('zh-cn', 'paypal') as $key => $value) {
    $_[$key] = $value;
}
