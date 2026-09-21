<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 观察者账户的创建人（谁建的谁管），写法对齐 merchants.owner_id 与
 * payment_methods.owner_id。
 *
 * 原来观察者记录没有任何归属字段，ObserverResource 只能用"绑定的支付方式里至少有
 * 一条我看得见"来划可见范围，编辑/删除更是完全没有记录级判断。系统级支付方式可以
 * 被分配给多个商户之后，这个口径破了：一个商户级管理员只要和某个观察者共享一条通道，
 * 就能改掉它的登录密码或直接删掉它——改完密码登录观察者面板，看到的是该观察者绑定的
 * 全部通道下的订单，包括他自己完全无权访问的那些。
 *
 * NULL 表示"平台直管"，只有超级管理员能管；存量记录保持 NULL，语义与
 * merchants.owner_id 的"平台直管"一致。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('observers', function (Blueprint $table) {
            $table->foreignId('owner_id')
                ->nullable()
                ->after('id')
                ->constrained('users')
                ->nullOnDelete()
                ->comment('创建人（超管/商户级管理员），NULL 表示平台直管，仅超管可维护');
        });
    }

    public function down(): void
    {
        Schema::table('observers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('owner_id');
        });
    }
};
