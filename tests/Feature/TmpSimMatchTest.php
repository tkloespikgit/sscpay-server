<?php
namespace Tests\Feature;

use App\Models\{Application, Merchant, PaymentGroup, PaymentMethod, PaymentMethodConfigMap, SiteProduct, SiteProductVariation};
use App\Services\OrderCreationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\{Http, Queue};
use Tests\TestCase;

class TmpSimMatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_sim(): void
    {
        Queue::fake();
        $this->seed(\Database\Seeders\PermissionSeeder::class);
        $this->seed(\Database\Seeders\SystemConfigSeeder::class);

        Http::fake([
            '*/wp-json/payment-plugin/v1/pay*' => Http::response([
                'code' => 0, 'message' => 'ok',
                'data' => ['pay_url' => 'https://pay.example.com/abc', 'wp_order_id' => 8899],
            ], 200),
            '*' => Http::response(['ok' => true], 200),
        ]);

        $merchant = Merchant::create(['name'=>'ACME 商贸','contact_person'=>'Toby','contact_phone'=>'123','contact_email'=>'m@e.com']);
        $app = Application::createWithCredentials(['merchant_id'=>$merchant->id,'name'=>'ACME 官网','website'=>'https://acme-shop.com']);
        PaymentGroup::create(['merchant_id'=>$merchant->id,'group_key'=>'group_default','group_name'=>'默认组','is_active'=>true]);
        $map = PaymentMethodConfigMap::create(['name'=>'Stripe 网关','payment_config_tag'=>'stripe_v3','fields'=>[]]);

        // 注意：domain 必须与订单 return_url 不同域，否则 isSameSite() 会绕过 MATCH 走直连
        $pm = PaymentMethod::create([
            'merchant_id'=>$merchant->id,'method_code'=>'stripe_eu','method_name'=>'Stripe 欧洲',
            'is_active'=>true,'config_map_id'=>$map->id,
            'domain'=>'https://wp-store.example.com','domain_client_id'=>'ck_live_xxx','domain_client_sk'=>'cs_live_yyy',
            'product_match_mode'=>PaymentMethod::MODE_MATCH,
            'config'=>['publishable_key'=>'pk_live_1','secret_key'=>'sk_live_1'],
            'invoice_prefix'=>'INV','virtual_product_prefix'=>'Digital Service',
            'allow_returned_source'=>true,'fee_percent'=>'3.5','fee_fixed'=>'0.30',
        ]);

        // 站点商品池（MATCH 就是从这里挑）
        foreach ([['Wireless Mouse',[19.90,29.90]],['Mechanical Keyboard',[59.00,89.00]],['USB-C Hub',[24.50,39.00]]] as [$name,$prices]) {
            $sp = SiteProduct::create([
                'merchant_id'=>$merchant->id,'payment_method_id'=>$pm->id,
                'woo_product_id'=>random_int(100,999),'product_type'=>'variable','name'=>$name,
                'sku'=>strtoupper(substr(md5($name),0,8)),
                'permalink'=>'https://wp-store.example.com/product/'.strtolower(str_replace(' ','-',$name)),
                'price_min'=>min($prices),'price_max'=>max($prices),
            ]);
            foreach ($prices as $i=>$p) {
                SiteProductVariation::create([
                    'site_product_id'=>$sp->id,'woo_variation_id'=>random_int(1000,9999),
                    'sku'=>$sp->sku.'-V'.$i,'price'=>$p,'currency'=>'USD',
                ]);
            }
        }

        // ---- 商户 POST /order/create 的业务数据（已由 CreateOrderRequest 扁平化） ----
        $data = [
            'merchant_order_no'=>'WC-10086','platform'=>'wordpress','currency'=>'USD',
            'group_key'=>'group_default','payment_method_key'=>'stripe_eu',
            'subtotal'=>'200.00','shipping_fee'=>'10.00','discount'=>'0.00','tax'=>'5.00','amount'=>'215.00',
            'customer_first_name'=>'John','customer_last_name'=>'Doe',
            'customer_email'=>'john@example.com','customer_phone'=>'+1-555-0100',
            'shipping_address_line1'=>'1 Market St','shipping_address_line2'=>'Suite 300',
            'shipping_city'=>'San Francisco','shipping_state'=>'CA','shipping_country'=>'US','shipping_zip'=>'94107',
            'items'=>[
                ['product_id'=>'SKU-A','product_sku'=>'SKU-A','product_url'=>'https://acme-shop.com/p/1','product_name'=>'Gaming Laptop Pro','unit_price'=>'150.00','quantity'=>1],
                ['product_id'=>'SKU-B','product_sku'=>'SKU-B','product_url'=>'https://acme-shop.com/p/2','product_name'=>'Laptop Sleeve','unit_price'=>'50.00','quantity'=>1],
            ],
            'notify_url'=>'https://wp-store.example.com/notify',
            'return_url'=>'https://wp-store.example.com/thanks',
            'cancel_url'=>'https://wp-store.example.com/cancel',
            'send_mail'=>'Y',
        ];

        $order = app(OrderCreationService::class)->createOrder($data, $merchant, $app, 'api');

        $req = collect(Http::recorded())->first(fn($p)=>str_contains($p[0]->url(),'/wp-json/payment-plugin/v1/pay'));
        $out = "\n".str_repeat('=',78)."\n";
        $out .= "POST ".$req[0]->url()."\n";
        $out .= str_repeat('=',78)."\n";
        foreach ($req[0]->headers() as $k=>$v) { if(in_array($k,['Accept','Content-Type'])) $out .= "$k: ".implode(',',$v)."\n"; }
        $out .= "\n".json_encode($req[0]->data(), JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
        $out .= str_repeat('-',78)."\n";
        $out .= "商户传入的 order_items   : ".$order->items->map(fn($i)=>"{$i->product_name} x{$i->quantity} @{$i->unit_price}")->implode(' | ')."\n";
        $out .= "MATCH 生成 matched_items : ".$order->matchedItems->map(fn($i)=>"{$i->product_name} x{$i->quantity} @{$i->unit_price}")->implode(' | ')."\n";
        $out .= "order.matched_discount   : {$order->matched_discount}\n";
        $out .= "order.subject            : {$order->subject}\n";
        $out .= "order.invoice_number     : {$order->invoice_number}\n";
        $out .= "回填 pay_url / wp_order_id: {$order->pay_url} / {$order->wp_order_id}\n";
        fwrite(STDERR, $out);
        $this->assertTrue(true);
    }
}
