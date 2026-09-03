<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100)->comment('商户名称');
            $table->string('contact_person', 100)->comment('联系人');
            $table->string('contact_phone', 30)->comment('联系电话');
            $table->string('contact_email', 255)->comment('联系邮箱');
            $table->json('allowed_domains')->comment('回调域名白名单，如 ["hat.com", "api.hat.com"]');
            $table->boolean('status')->default(true)->comment('启用/禁用');
            $table->text('remark')->nullable()->comment('备注');
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchants');
    }
};
