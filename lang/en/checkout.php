<?php

/*
| 收款链接落地页（面向终端客户）的文案。
|
| ⚠️ 这份语言包和各语言下的 admin.php 定位不同：admin.php 是后台界面，跟随
| APP_LOCALE；落地页面向的是海外买家，**固定英文**，不跟随系统语言设置。
| 所以只有 en 一份，视图里用 __('checkout.xxx') 取值也只会命中这份文件
| （见 CheckoutLinkController，渲染前不切换 locale —— 因为落地页不提供
| 其他语言，切了反而会退化成显示原始 key）。
|
| 之所以仍然走语言包而不是把英文硬编码进 Blade：文案散落在模板里之后，
| 商户提出改一句话都要翻模板找，而且将来真要加多语言时无从下手。
*/

return [

    'page_title' => ':title — Secure Checkout',

    'sections' => [
        'secure_checkout' => 'Secure checkout',
        'order_summary' => 'Order Summary',
        'contact' => 'Contact Information',
        'shipping' => 'Shipping Address',
        'payment' => 'Payment',
    ],

    'fields' => [
        'first_name' => 'First name',
        'last_name' => 'Last name',
        'email' => 'Email address',
        'phone' => 'Phone number',
        'address_line1' => 'Address',
        'address_line2' => 'Apartment, suite, etc. (optional)',
        'city' => 'City',
        'state' => 'State / Province',
        'country' => 'Country',
        'zip' => 'ZIP / Postal code',
        'amount' => 'Amount',
    ],

    'placeholders' => [
        'address_line1' => 'Start typing your address…',
        'select_country_first' => 'Select a country first',
        'select_state_first' => 'Select a state / province first',
        'select_state' => 'Select a state / province',
        'select_city' => 'Select a city',
        'enter_manually' => 'Enter manually',
        'enter_state' => 'Enter state / province',
        'enter_city' => 'Enter city',
        'phone' => '+1 555 000 0000',
        'search_country' => 'Search countries…',
        'select_country' => 'Select a country or region',
        'no_countries' => 'No countries found',
        'no_states' => 'No states or provinces found',
        'no_cities' => 'No cities found',
        'amount' => '0.00',
    ],

    'help' => [
        'phone' => 'Include your country code, for example +49 151 1234 5678.',
        'amount_range' => 'Enter an amount between :min and :max.',
        'address_autocomplete' => 'Select your address from the suggestions to fill in the fields automatically.',
    ],

    'summary' => [
        'subtotal' => 'Subtotal',
        'shipping' => 'Shipping',
        'tax' => 'Tax',
        'discount' => 'Discount',
        'total' => 'Total',
        'quantity' => 'Qty :count',
    ],

    'actions' => [
        'pay' => 'Continue to payment',
        'submitting' => 'Processing…',
    ],

    'validation' => [
        'phone_needs_country_code' => 'Please include your country code, starting with +.',
        'phone_invalid' => 'This phone number does not look valid. Please check and try again.',
        'phone_country_not_supported' => 'This payment link does not support phone numbers from that country.',
        'captcha_failed' => 'Verification failed. Please tick the verification box and try again.',
        'form_expired' => 'This page has expired. Please refresh and fill in the form again.',
        'submission_rejected' => 'We could not process this submission. Please refresh the page and try again.',
        'too_many_attempts' => 'Too many attempts. Please wait a while before trying again.',
        'link_unavailable' => 'This payment link is temporarily unavailable. Please contact the merchant.',
    ],

    'errors' => [
        'order_failed' => 'We could not start your payment. Please try again in a few minutes, or contact the merchant if the problem continues.',
        'not_available' => 'This payment link is no longer available.',
    ],

    'result' => [
        'success_title' => 'Thank you',
        'success_body' => 'We have received your request. A confirmation will be sent to your email once the payment is fully processed.',
        'cancelled_title' => 'Payment cancelled',
        'cancelled_body' => 'Your payment was not completed. You can go back and try again.',
        'order_no' => 'Order number',
        'status' => 'Status',
        'back' => 'Back to checkout',
    ],

    'footer' => [
        'secure' => 'Payments are processed securely.',
    ],

];
