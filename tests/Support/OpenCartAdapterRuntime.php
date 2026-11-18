<?php

namespace Opencart\System\Engine {
    class Controller
    {
        public function __construct(protected mixed $registry) {}

        public function __get(string $key): mixed
        {
            return $this->registry->get($key);
        }
    }

    class Model extends Controller {}
}

namespace {
    use Tests\Support\OpenCartRuntime;

    require dirname(__DIR__, 2).'/vendor/autoload.php';
    $directory = $argv[1];
    $major = (int) $argv[2];
    define('DIR_SYSTEM', $directory.'/upload/system/');
    define('DIR_EXTENSION', $directory.'/extension/');
    define('DB_PREFIX', 'oc_adapter_');
    $shared = $major === 3 ? DIR_SYSTEM.'library/sscpay/' : DIR_EXTENSION.'sscpay/system/library/sscpay/';
    require $shared.'bootstrap.php';
    require __DIR__.'/OpenCartRuntime.php';
    class Controller extends Opencart\System\Engine\Controller {}
    class Model extends Opencart\System\Engine\Model {}
    $result = ['codes' => [], 'options' => [], 'checkout' => []];
    foreach (['paypal', 'creditcard'] as $channel) {
        $registry = OpenCartRuntime::registry($channel, $major);
        $root = $major === 3 ? $directory.'/upload/catalog/' : DIR_EXTENSION.'sscpay/catalog/';
        $sub = $major === 3 ? 'extension/payment/' : 'payment/';
        require $root.'model/'.$sub.'sscpay'.$channel.'.php';
        require $root.'controller/'.$sub.'sscpay'.$channel.'.php';
        $modelClass = $major === 3 ? 'ModelExtensionPaymentSscpay'.$channel : 'Opencart\\Catalog\\Model\\Extension\\Sscpay\\Payment\\Sscpay'.$channel;
        $controllerClass = $major === 3 ? 'ControllerExtensionPaymentSscpay'.$channel : 'Opencart\\Catalog\\Controller\\Extension\\Sscpay\\Payment\\Sscpay'.$channel;
        $model = new $modelClass($registry);
        $result['codes'][] = $model->getMethod([], 100)['code'];
        if ($major === 4) {
            $result['options'][] = $model->getMethods([])['option']['sscpay'.$channel]['code'];
        }
        $result['checkout'][] = (new $controllerClass($registry))->index() === 'rendered-view';
    }
    echo json_encode($result, JSON_THROW_ON_ERROR);
}
