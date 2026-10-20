<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('purchase_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_method_id')->constrained()->restrictOnDelete();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->char('order_no', 19)->unique();
            $table->char('transaction_id', 34)->unique();
            $table->char('currency', 3)->default('CNY');
            $table->dateTime('ordered_at');
            $table->dateTime('paid_at');
            $table->dateTime('shipped_at');
            $table->dateTime('received_at');
            $table->decimal('subtotal', 20, 2);
            $table->decimal('shipping_fee', 20, 2);
            $table->decimal('discount', 20, 2);
            $table->decimal('total', 20, 2);
            $table->timestamps();

            $table->index(['payment_method_id', 'ordered_at']);
        });

        Schema::create('purchase_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('site_product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name', 500);
            $table->string('sku')->nullable();
            $table->decimal('source_price', 20, 2);
            $table->decimal('unit_price', 20, 2);
            $table->unsignedInteger('quantity');
            $table->decimal('subtotal', 20, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('purchase_order_items');
        Schema::dropIfExists('purchase_orders');
    }
};
