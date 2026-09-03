@component('mail::message')
{{ __('admin.mail.payment_link.greeting', ['customer_name' => $customerName]) }}

{{ __('admin.mail.payment_link.intro', ['amount' => $order->amount, 'currency' => $order->currency]) }}

@component('mail::button', ['url' => $paymentUrl])
{{ __('admin.mail.payment_link.cta') }}
@endcomponent

{{ __('admin.mail.payment_link.expires', ['expire_date' => $expireDate]) }}

{{ __('admin.mail.payment_link.help', ['merchant' => $order->merchant->name]) }}
@endcomponent
