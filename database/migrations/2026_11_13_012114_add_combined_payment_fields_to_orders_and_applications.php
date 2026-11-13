<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table): void {
            $table->boolean('is_combine_payment_enabled')->default(false);
            $table->unsignedTinyInteger('webhook_version')->default(1);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->foreignId('combined_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('combined_sequence')->nullable();
            $table->unique(['combined_order_id', 'combined_sequence']);
            $table->string('risk_reservation_status', 16)->nullable();
            $table->timestamp('risk_reserved_at')->nullable();
            $table->index(['payment_method_id', 'risk_reservation_status'], 'orders_channel_reservations_index');
        });
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE orders MODIFY merchant_order_no VARCHAR(80) NOT NULL, MODIFY merchant_order_no_uniq VARCHAR(80) GENERATED ALWAYS AS (IF(deleted_at IS NULL, merchant_order_no, NULL)) VIRTUAL');
        }
        Schema::table('order_notification_attempts', function (Blueprint $table): void {
            $table->foreignId('combined_order_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('event_id', 64)->nullable();
            $table->unsignedInteger('event_version')->nullable();
            $table->unique(['event_id', 'attempt_number']);
        });
    }

    public function down(): void
    {
        Schema::table('order_notification_attempts', function (Blueprint $table): void {
            $table->dropUnique(['event_id', 'attempt_number']);
            $table->dropConstrainedForeignId('combined_order_id');
            $table->dropColumn(['event_id', 'event_version']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropForeign(['combined_order_id']);
            $table->dropUnique(['combined_order_id', 'combined_sequence']);
            $table->dropIndex('orders_channel_reservations_index');
            $table->dropColumn('combined_order_id');
            $table->dropColumn(['combined_sequence', 'risk_reservation_status', 'risk_reserved_at']);
        });
        Schema::table('applications', function (Blueprint $table): void {
            $table->dropColumn(['is_combine_payment_enabled', 'webhook_version']);
        });
        // 保留扩展后的编号长度，避免回滚截断已经生成的子单编号。
    }
};
