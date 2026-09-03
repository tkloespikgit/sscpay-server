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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255)->comment('姓名');
            $table->string('email', 255)->unique()->comment('登录邮箱（唯一）');
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255)->comment('加密密码');
            $table->boolean('is_super_admin')->default(false)->comment('是否超级管理员（true 可管理所有商户）');
            $table->text('app_authentication_secret')->nullable()->comment('MFA TOTP 密钥（Filament 内置模块管理）');
            $table->text('app_authentication_recovery_codes')->nullable()->comment('MFA 恢复码（加密存储）');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();

            $table->index('is_super_admin');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
