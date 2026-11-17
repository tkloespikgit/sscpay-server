<?php

namespace Tests\Feature;

use App\Models\TelegramBindingRequest;
use App\Models\TelegramBot;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

class TelegramAdminOwnershipMigrationTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase {
        beginDatabaseTransaction as private beginRefreshDatabaseTransaction;
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue($this->app->environment('testing'));
        $this->assertFalse($this->app->configurationIsCached());
        $connection = (string) config('database.default');
        $this->assertSame('', (string) config('database.connections.'.$connection.'.url'));

        if ($connection === 'sqlite') {
            $this->assertSame(':memory:', config('database.connections.sqlite.database'));

            return;
        }

        $database = (string) config('database.connections.mysql.database');
        $this->assertSame('mysql', $connection);
        $this->assertSame('testing', getenv('APP_ENV'));
        $this->assertSame('', getenv('DB_URL'));
        $this->assertMatchesRegularExpression('/\Asscpay_test_[a-f0-9]{16}\z/', $database);
        $this->assertSame($database, DB::selectOne('SELECT DATABASE() AS database_name')->database_name);
        RefreshDatabaseState::$migrated = false;
    }

    /** MySQL DDL 隐式提交，隔离库中逐例重建，不能套测试外层事务。 */
    public function beginDatabaseTransaction(): void
    {
        if (config('database.default') !== 'mysql') {
            $this->beginRefreshDatabaseTransaction();

            return;
        }

        $this->beforeApplicationDestroyed(function (): void {
            RefreshDatabaseState::$migrated = false;
            DB::disconnect();
        });
    }

    public function test_merchant_bindings_survive_rollback_and_reapplication(): void
    {
        Http::preventStrayRequests();
        $this->createMerchantAndApplication();
        $actor = User::factory()->create(['merchant_id' => $this->merchant->id]);
        $bot = TelegramBot::create([
            'merchant_id' => $this->merchant->id, 'bot_token' => 'migration-test-token',
            'chat_id' => '12345', 'is_enabled' => true,
        ]);
        $binding = $this->createBinding($actor, $this->merchant->id);
        $botBefore = $bot->fresh()->getAttributes();
        $bindingBefore = $binding->fresh()->getAttributes();

        $this->migration()->down();

        $this->assertFalse(Schema::hasColumn('telegram_bots', 'user_id'));
        $this->assertFalse(Schema::hasColumn('telegram_binding_requests', 'target_user_id'));
        $this->assertEquals(array_diff_key($botBefore, array_flip(['user_id', 'user_id_uniq'])), $bot->fresh()->getAttributes());
        $this->assertEquals(array_diff_key($bindingBefore, ['target_user_id' => true]), $binding->fresh()->getAttributes());

        $this->migration()->up();

        $this->assertEquals($botBefore, $bot->fresh()->getAttributes());
        $this->assertEquals($bindingBefore, $binding->fresh()->getAttributes());
        Http::assertNothingSent();
    }

    #[TestWith(['bot', false])]
    #[TestWith(['bot', true])]
    #[TestWith(['binding', false])]
    public function test_admin_data_blocks_rollback_before_any_columns_are_removed(string $type, bool $deleted): void
    {
        Http::preventStrayRequests();
        $admin = User::factory()->create(['merchant_id' => null, 'is_super_admin' => true]);
        $record = $type === 'bot'
            ? TelegramBot::create([
                'user_id' => $admin->id, 'bot_token' => 'admin-migration-test-token',
                'chat_id' => '67890', 'is_enabled' => true,
            ])
            : $this->createBinding($admin);

        if ($deleted) {
            $record->delete();
        }

        $before = DB::table($record->getTable())->where('id', $record->id)->first();

        try {
            $this->migration()->down();
            $this->fail('Rollback must preserve administrator Telegram data.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Cannot roll back Telegram administrator ownership while administrator configurations or binding requests exist.', $exception->getMessage());
        }

        $this->assertTrue(Schema::hasColumn('telegram_bots', 'user_id'));
        $this->assertTrue(Schema::hasColumn('telegram_binding_requests', 'target_user_id'));
        $this->assertEquals($before, DB::table($record->getTable())->where('id', $record->id)->first());
        Http::assertNothingSent();
    }

    private function createBinding(User $actor, ?int $merchantId = null): TelegramBindingRequest
    {
        return TelegramBindingRequest::create([
            'merchant_id' => $merchantId,
            'target_user_id' => $merchantId === null ? $actor->id : null,
            'user_id' => $actor->id,
            'token_hash' => hash('sha256', 'migration-test-binding'),
            'bot_fingerprint' => hash('sha256', 'migration-test-bot'),
            'chat_type' => 'private',
            'expires_at' => now()->addMinutes(10),
        ]);
    }

    private function migration(): Migration
    {
        return require database_path('migrations/2026_11_16_152606_add_admin_owner_to_telegram_tables.php');
    }
}
