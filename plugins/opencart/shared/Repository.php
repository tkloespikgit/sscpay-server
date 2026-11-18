<?php
namespace Sscpay\OpenCart;

use RuntimeException;
use Throwable;

/** Extension-owned state. Uninstall deliberately retains reconciliation records. */
class Repository {
    private $db;
    private string $table;

    public function __construct($db) {
        $this->db = $db;
        $this->table = DB_PREFIX . 'sscpay_order';
    }

    public function install(): void {
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . $this->table . "` (
            `store_id` INT UNSIGNED NOT NULL, `order_id` INT UNSIGNED NOT NULL,
            `gateway` VARCHAR(20) NOT NULL, `app_id` VARCHAR(100) NOT NULL, `api_url` VARCHAR(500) NOT NULL,
            `merchant_order_no` VARCHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `remote_order_no` VARCHAR(80) NOT NULL DEFAULT '', `currency` CHAR(3) NOT NULL,
            `amount` DECIMAL(18,2) NOT NULL, `pay_url` TEXT NOT NULL, `return_token` CHAR(64) NOT NULL,
            `state_json` MEDIUMTEXT NOT NULL, `date_added` DATETIME NOT NULL, `date_modified` DATETIME NOT NULL,
            PRIMARY KEY (`store_id`, `order_id`), UNIQUE KEY `merchant_order_no` (`merchant_order_no`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $this->db->query("CREATE TABLE IF NOT EXISTS `" . DB_PREFIX . "sscpay_nonce` (
            `nonce_hash` CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
            `body_hash` CHAR(64) NOT NULL, `expires_at` BIGINT NOT NULL, PRIMARY KEY (`nonce_hash`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    public function locked(int $store, int $order, callable $callback) {
        $name = 'sscpay:' . substr(hash('sha256', DB_DATABASE . ':' . DB_PREFIX . ':' . $store . ':' . $order), 0, 48);
        $result = $this->db->query("SELECT GET_LOCK('" . $name . "', 0) AS acquired");
        if ((int)($result->row['acquired'] ?? 0) !== 1) {
            throw new RuntimeException('Order is busy. Please retry.');
        }
        try {
            return $callback();
        } finally {
            $this->db->query("SELECT RELEASE_LOCK('" . $name . "')");
        }
    }

    public function transaction(callable $callback) {
        $this->db->query('START TRANSACTION');
        try {
            $result = $callback();
            $this->db->query('COMMIT');
            return $result;
        } catch (Throwable $error) {
            $this->db->query('ROLLBACK');
            throw $error;
        }
    }

    public function find(int $store, int $order): ?array {
        $query = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `store_id` = " . $store . " AND `order_id` = " . $order);
        return !empty($query->row) ? $this->decode($query->row) : null;
    }

    public function byReference(int $store, string $reference): ?array {
        $query = $this->db->query("SELECT * FROM `" . $this->table . "` WHERE `store_id` = " . $store
            . " AND `merchant_order_no` = '" . $this->db->escape($reference) . "'");
        return !empty($query->row) ? $this->decode($query->row) : null;
    }

    public function insert(array $record): void {
        $values = [];
        foreach (['store_id', 'order_id', 'gateway', 'app_id', 'api_url', 'merchant_order_no', 'remote_order_no',
            'currency', 'amount', 'pay_url', 'return_token'] as $key) {
            $values[] = '`' . $key . "` = '" . $this->db->escape((string)$record[$key]) . "'";
        }
        $values[] = "`state_json` = '" . $this->db->escape(json_encode($record['state'], JSON_THROW_ON_ERROR)) . "'";
        $this->db->query("INSERT INTO `" . $this->table . "` SET " . implode(', ', $values) . ', date_added = NOW(), date_modified = NOW()');
    }

    public function save(array $record): void {
        $this->db->query("UPDATE `" . $this->table . "` SET `remote_order_no` = '" . $this->db->escape($record['remote_order_no'])
            . "', `pay_url` = '" . $this->db->escape($record['pay_url']) . "', `state_json` = '"
            . $this->db->escape(json_encode($record['state'], JSON_THROW_ON_ERROR)) . "', `date_modified` = NOW() WHERE `store_id` = "
            . (int)$record['store_id'] . ' AND `order_id` = ' . (int)$record['order_id']);
    }

    public function nonce(string $app, string $nonce, array $body): void {
        $hash = hash('sha256', $app . "\0" . $nonce);
        $bodyHash = hash('sha256', Signature::canonical($body));
        $this->db->query("DELETE FROM `" . DB_PREFIX . "sscpay_nonce` WHERE `expires_at` < " . time() . ' LIMIT 100');
        $this->db->query("INSERT IGNORE INTO `" . DB_PREFIX . "sscpay_nonce` SET `nonce_hash` = '" . $hash
            . "', `body_hash` = '" . $bodyHash . "', `expires_at` = " . (time() + 600));
        $existing = $this->db->query("SELECT `body_hash` FROM `" . DB_PREFIX . "sscpay_nonce` WHERE `nonce_hash` = '" . $hash . "'");
        if (!hash_equals($bodyHash, (string)($existing->row['body_hash'] ?? ''))) {
            throw new RuntimeException('Reused notification nonce.');
        }
    }

    private function decode(array $row): array {
        $row['state'] = json_decode($row['state_json'], true, 512, JSON_THROW_ON_ERROR);
        return $row;
    }
}
