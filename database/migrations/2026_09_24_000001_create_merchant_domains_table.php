<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 商户自有域名（收款链接落地页用）。商户把自己的子域名 CNAME 到平台的
 * 回退源站主机名，平台侧通过 Cloudflare for SaaS 自定义主机名为这个域名
 * 签发证书，落地页即可跑在商户自己的品牌域名上。
 *
 * 为什么不是直接在服务器上加 Nginx server_name：那样每接一个商户都要改
 * 生产配置 + 跑一次 certbot，不可扩展；Cloudflare for SaaS 把证书签发与续期
 * 全部挪到边缘，源站配置零改动（只需把回退源站主机名加进现有 server_name）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_domains', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete()->comment('所属商户');
            $table->string('host', 255)->comment('商户绑定的域名（小写、不含协议与端口，如 checkout.tvbox.com）');

            // 归属验证：系统生成一串随机 token，商户把它加成 TXT 记录，后台点"验证"时
            // 做一次 DNS 查询确认。没有这一步的话，任何商户都能把别人的域名填进来，
            // 进而让指向那个域名的流量落到自己的收款链接上。
            $table->string('verify_token', 64)->comment('DNS 归属验证用的随机串（加在 TXT 记录里）');
            $table->timestamp('verified_at')->nullable()->comment('DNS 归属验证通过时间，为空表示尚未验证');
            $table->string('last_verify_error', 500)->nullable()->comment('最近一次验证失败原因，验证通过后清空');

            // Cloudflare for SaaS 自定义主机名。验证通过后调 CF API 注册，
            // CF 侧的证书签发是异步的（DCV 完成后才 active），所以状态要落库轮询。
            $table->string('cf_hostname_id', 64)->nullable()->comment('Cloudflare 自定义主机名 ID（调 CF API 后回填）');
            $table->string('cf_ssl_status', 32)->nullable()->comment('CF 证书状态：pending_validation / active / ... 原样存 CF 返回值');
            $table->string('cf_last_error', 500)->nullable()->comment('最近一次调用 CF API 或证书校验的失败原因');
            $table->json('cf_dcv_records')->nullable()->comment('CF 返回的 DCV 校验记录（商户需自行添加），原样存放供后台展示');
            $table->timestamp('cf_synced_at')->nullable()->comment('最近一次与 CF 同步状态的时间');

            $table->boolean('is_active')->default(true)->comment('是否启用（停用后该域名上的收款链接一律 404）');
            $table->timestamps();
            $table->softDeletes();

            $table->index('merchant_id');

            // 软删除安全的唯一约束：见 orders 表注释，MySQL 唯一索引对 NULL 不去重，
            // 用虚拟生成列使 host 仅在未删除记录间全局唯一。
            //
            // 唯一性是全局的而不是按商户的——同一个域名只可能属于一个商户，
            // 允许两个商户绑同一个 host，按 Host 头路由时就无法判定该落到谁的链接上。
            $table->string('host_uniq', 255)
                ->nullable()
                ->virtualAs('IF(deleted_at IS NULL, host, NULL)')
                ->comment('生成列：仅未删除域名参与 host 唯一性校验');
            $table->unique('host_uniq');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_domains');
    }
};
