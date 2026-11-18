<?php

use Sscpay\OpenCart\Gateway;
use Sscpay\OpenCart\Signature;
use Tests\Support\OpenCartOrderModel;
use Tests\Support\OpenCartRuntime;
use Tests\Support\OpenCartSqlDriver;

require dirname(__DIR__, 2).'/vendor/autoload.php';
require __DIR__.'/OpenCartRuntime.php';
require __DIR__.'/OpenCartSqlDriver.php';

$database = $argv[1];
if (! preg_match('/^sscpay_oc_test_[a-f0-9]{16}$/D', $database)) {
    throw new RuntimeException('Unexpected test database.');
}
define('DB_DATABASE', $database);
$pdo = new PDO(getenv('OPENCART_TEST_MYSQL_DSN').';dbname='.$database, getenv('OPENCART_TEST_MYSQL_USER'), getenv('OPENCART_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$registry = OpenCartRuntime::registry();
$registry->services['db'] = new OpenCartSqlDriver($pdo);
$registry->services['model_checkout_order'] = new class($pdo) extends OpenCartOrderModel
{
    public function __construct(private PDO $pdo) {}

    public function getOrder(int $id): array
    {
        return $this->pdo->query('SELECT * FROM oc_test_order WHERE order_id = '.(int) $id)->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    public function addOrderHistory(int $id, int $status, string $comment, bool $notify): void
    {
        $this->pdo->exec('UPDATE oc_test_order SET order_status_id = '.(int) $status.' WHERE order_id = '.(int) $id);
        $this->pdo->exec('INSERT INTO oc_test_history (order_id, status) VALUES ('.(int) $id.','.(int) $status.')');
    }
};
$gateway = new Gateway($registry, 3, 'paypal');
$record = OpenCartRuntime::record();
$body = OpenCartRuntime::event($record, '100.00', 2);
$timestamp = (string) time();
$nonce = bin2hex(random_bytes(16));
$body['sign'] = Signature::sign('app-test', $timestamp, $nonce, $body, 'secret-test');
for ($attempt = 0; $attempt < 50; $attempt++) {
    try {
        $gateway->notification('notify', $body, ['App-ID' => 'app-test', 'Timestamp' => $timestamp, 'X-Nonce' => $nonce]);
        echo 'accepted';
        exit(0);
    } catch (RuntimeException $error) {
        if ($error->getMessage() !== 'Order is busy. Please retry.') {
            throw $error;
        }
        usleep(20000);
    }
}
throw new RuntimeException('Callback retry limit reached.');
