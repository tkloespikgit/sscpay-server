<?php

namespace Tests\Feature;

use PDO;
use RuntimeException;
use Sscpay\OpenCart\Repository;
use Symfony\Component\Process\Process;
use Tests\Support\OpenCartRuntime;
use Tests\Support\OpenCartSqlDriver;
use Tests\TestCase;

require_once dirname(__DIR__).'/Support/OpenCartRuntime.php';
require_once dirname(__DIR__).'/Support/OpenCartSqlDriver.php';

class OpenCartStorageTest extends TestCase
{
    public function test_isolated_mysql_storage_rolls_back_deduplicates_and_serializes_four_payment_callbacks(): void
    {
        $dsn = getenv('OPENCART_TEST_MYSQL_DSN');
        if (! $dsn) {
            $this->markTestSkipped('Set OPENCART_TEST_MYSQL_DSN for a local isolated MySQL test; application DB configuration is never used.');
        }
        $this->assertMatchesRegularExpression('/^mysql:host=(?:127\.0\.0\.1|localhost);port=\d+$/D', $dsn);
        $pdo = new PDO($dsn, getenv('OPENCART_TEST_MYSQL_USER'), getenv('OPENCART_TEST_MYSQL_PASSWORD'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $database = 'sscpay_oc_test_'.bin2hex(random_bytes(8));
        $pdo->exec('CREATE DATABASE `'.$database.'` CHARACTER SET utf8mb4');
        try {
            $pdo->exec('USE `'.$database.'`');
            $repository = new Repository(new OpenCartSqlDriver($pdo));
            $repository->install();
            $repository->install();
            $record = OpenCartRuntime::record();
            $repository->insert($record);
            $this->assertSame('100.00', $repository->find(0, 42)['amount']);
            $this->assertNull($repository->find(1, 42));
            $this->assertNull($repository->byReference(0, "' OR 1=1 --"));
            try {
                $repository->transaction(function () use ($repository, $record): void {
                    $record['state'] = ['rollback' => true];
                    $repository->save($record);
                    throw new RuntimeException('rollback');
                });
            } catch (RuntimeException $error) {
                $this->assertSame('rollback', $error->getMessage());
            }
            $this->assertSame([], $repository->find(0, 42)['state']);
            $repository->nonce('app-test', 'a-nonce-1234', ['amount' => '100.00']);
            $repository->nonce('app-test', 'a-nonce-1234', ['amount' => '100.00']);
            try {
                $repository->nonce('app-test', 'a-nonce-1234', ['amount' => '99.00']);
                $this->fail('Nonce was reused with different contents.');
            } catch (RuntimeException $error) {
                $this->assertSame('Reused notification nonce.', $error->getMessage());
            }
            $pdo->exec('CREATE TABLE oc_test_order (order_id INT PRIMARY KEY, store_id INT, order_status_id INT) ENGINE=InnoDB');
            $pdo->exec('CREATE TABLE oc_test_history (id INT AUTO_INCREMENT PRIMARY KEY, order_id INT, status INT) ENGINE=InnoDB');
            $pdo->exec('INSERT INTO oc_test_order VALUES (42,0,1)');
            $processes = [];
            for ($i = 0; $i < 4; $i++) {
                $process = new Process([PHP_BINARY, base_path('tests/Support/OpenCartConcurrentCallback.php'), $database], base_path());
                $process->start();
                $processes[] = $process;
            }
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $this->assertSame('accepted', $process->getOutput());
            }
            $this->assertSame('paid', $repository->find(0, 42)['state']['stage']);
            $this->assertSame(1, (int) $pdo->query('SELECT COUNT(*) FROM oc_test_history WHERE status = 2')->fetchColumn());
            $this->assertSame(2, (int) $pdo->query('SELECT order_status_id FROM oc_test_order WHERE order_id = 42')->fetchColumn());
            $this->assertCount(1, $repository->find(0, 42)['state']['events']);
        } finally {
            $pdo->exec('DROP DATABASE `'.$database.'`');
            $this->assertSame(0, (int) $pdo->query('SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '.$pdo->quote($database))->fetchColumn());
        }
    }
}
