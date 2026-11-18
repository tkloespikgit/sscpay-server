<?php
require_once DIR_EXTENSION . 'sscpay/system/library/sscpay/bootstrap.php';
foreach (\Sscpay\OpenCart\Text::all('en-gb', 'creditcard') as $key => $value) {
    $_[$key] = $value;
}
