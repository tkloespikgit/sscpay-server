<?php
/**
 * 每站独立的对外端点形态（REST命名空间 / 路径段 / token参数名）。
 *
 * 为什么要这个：给支付通道的 return_url / cancel_url / webhook 地址是通道
 * 长期保存、并且会拿来做商户关联分析的数据。如果所有装了本插件的站点都长成
 * 完全一样的 `/wp-json/payment-plugin/v1/paypal-rest/return?pga_token=...`，
 * 那么任意一个客户因为违规销售被通道标记，通道就可以顺着这个逐字符相同的
 * URL 形态，把其它所有用本插件的站点一并关联进去——这不是假想，支付机构和
 * 风控服务商确实会用共享基础设施特征给商户做聚类。
 *
 * 所以这里在每个站点首次使用时随机生成一套自己的形态，落 option 永不重生成。
 *
 * 【永不删除、永不重生成】三条硬约束：
 * 1. 出站 URL 已经交给了通道（PayPal 存在订单里、webhook 地址存在账户配置里），
 *    名字一变，在途订单跳回来就找不到路由，买家付了钱但 capture 不会触发；
 * 2. 所以旧形态 payment-plugin/v1 + pga_token 永久保留并继续注册（见
 *    API/Routes/api.php 同时注册两套），只是不再用于新下发的地址；
 * 3. uninstall.php 不删这个 option——交易表本来就是故意保留的（财务凭证），
 *    option 删了而表还在，等于把历史订单的回跳地址弄丢。
 *
 * 词表都用中性的业务词汇：既不跨站共享、单独看又像个普通的商城接口，
 * 不会因为一串乱码路径引来人工复核时的额外注意。
 */

namespace PaymentGatewayAggregator\Core;

if (!defined('ABSPATH')) {
    exit;
}

class RouteIdentity
{
    /** 存放本站形态的 option（autoload，几乎每个请求都要读）。 */
    const OPTION = 'pga_route_identity';

    /** 1.5.0 之前的固定形态。永久保留：在途订单和已登记的 webhook 还在用。 */
    const LEGACY_NAMESPACE   = 'payment-plugin/v1';
    const LEGACY_TOKEN_PARAM = 'pga_token';

    /** @var array|null 进程内缓存，避免一次请求里反复 get_option。 */
    private static $cache = null;

    private static $namespaceHeads = [
        'store', 'shop', 'order', 'checkout', 'commerce', 'retail',
        'merchant', 'basket', 'purchase', 'billing', 'catalog', 'market',
        'vendor', 'client', 'account', 'customer',
    ];

    private static $namespaceTails = [
        'api', 'bridge', 'gateway', 'relay', 'service', 'connect',
        'link', 'hub', 'desk', 'core', 'portal', 'channel',
        'endpoint', 'engine', 'node', 'proxy',
    ];

    private static $returnWords  = ['return', 'complete', 'confirm', 'finish', 'done', 'settled'];
    private static $cancelWords  = ['cancel', 'abort', 'dropped', 'void', 'stopped', 'back'];
    private static $captureWords = ['capture', 'collect', 'settle', 'charge', 'finalize', 'submit'];
    private static $notifyWords  = ['webhook', 'notify', 'callback', 'events', 'hook', 'push'];

    /**
     * token 参数名。刻意【不】包含 'token' 和 'PayerID'：PayPal 跳回
     * return_url/cancel_url 时会自己追加这两个同名参数，撞名的话 PHP 解析
     * 重复 query key 只保留最后一个，我们的值会被 PayPal 的覆盖掉。
     * 也避开 WP REST 的保留参数（_method/_envelope/rest_route 等）。
     */
    private static $tokenParams = ['sid', 'ref', 'rk', 'sref', 'ptk', 'cid', 'ak', 'xref', 'skey', 'tref'];

    /**
     * 本站的 REST 命名空间，形如 `order-bridge/v1`。
     *
     * @return string
     */
    public static function namespaceSlug()
    {
        return self::get('namespace');
    }

    /**
     * PayPal 回跳中转端点的路径段（不含前导斜杠的父段，见 routes/api.php）。
     *
     * @return string
     */
    public static function returnPath()
    {
        return self::get('return');
    }

    public static function cancelPath()
    {
        return self::get('cancel');
    }

    public static function capturePath()
    {
        return self::get('capture');
    }

    public static function notifyPath()
    {
        return self::get('notify');
    }

    public static function tokenParam()
    {
        return self::get('token_param');
    }

    /**
     * 从请求里读 token：先认本站的参数名，再认旧的 pga_token。
     *
     * 兜底分支永远保留——它覆盖的是"订单在旧版本下单、在新版本跳回来"，
     * 以及 option 因为任何原因丢失后重新生成的情况。
     *
     * @param \WP_REST_Request $request
     * @return string
     */
    public static function readToken(\WP_REST_Request $request)
    {
        $token = $request->get_param(self::tokenParam());
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $legacy = $request->get_param(self::LEGACY_TOKEN_PARAM);

        return is_string($legacy) ? $legacy : '';
    }

    /**
     * 本站某个端点的完整对外地址，给管理员抄到通道后台用（见 Admin/SettingsPage）。
     *
     * @param string $path 形如 '/webhook/paypal'
     * @return string
     */
    public static function restUrl($path)
    {
        return rest_url(self::namespaceSlug() . '/' . ltrim($path, '/'));
    }

    /**
     * 取某一项，缺失/损坏时就地补一个（不覆盖已有的其它项）。
     *
     * @param string $key
     * @return string
     */
    private static function get($key)
    {
        if (self::$cache === null) {
            self::$cache = self::load();
        }

        return self::$cache[$key];
    }

    /**
     * 读 option；没有就生成一套写进去。
     *
     * 用 add_option 而不是 update_option：add_option 在 option 已存在时直接返回
     * false 不覆盖，天然挡住并发下两个请求同时生成、后者盖掉前者（那会让前者
     * 刚刚发给通道的地址当场失效）。写完重新读一次，以先落库的那套为准。
     *
     * @return array
     */
    private static function load()
    {
        $stored = get_option(self::OPTION);

        if (self::isComplete($stored)) {
            return $stored;
        }

        // option 不存在，或存在但被改坏/是早期不完整的版本：补齐缺失项后写回。
        $identity = self::generate();
        if (is_array($stored)) {
            foreach ($stored as $key => $value) {
                if (isset($identity[$key]) && is_string($value) && $value !== '') {
                    $identity[$key] = $value;
                }
            }
        }

        if (!add_option(self::OPTION, $identity, '', 'yes')) {
            update_option(self::OPTION, $identity);
        }

        $reloaded = get_option(self::OPTION);

        return self::isComplete($reloaded) ? $reloaded : $identity;
    }

    /**
     * @param mixed $identity
     * @return bool
     */
    private static function isComplete($identity)
    {
        if (!is_array($identity)) {
            return false;
        }

        foreach (['namespace', 'return', 'cancel', 'capture', 'notify', 'token_param'] as $key) {
            if (!isset($identity[$key]) || !is_string($identity[$key]) || $identity[$key] === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * 各段独立随机。单段的取值范围不大（词表都是十几个），但整条 URL 的组合
     * 空间足够大——通道想用一条精确匹配规则同时命中两个站点基本不可能；
     * 而退一步用单个词去匹配（比如"路径里含 /settle"），那种规则会同时命中
     * 大量与本插件无关的正常站点，对通道自己也没有使用价值。
     *
     * @return array
     */
    private static function generate()
    {
        return [
            'namespace'   => self::pick(self::$namespaceHeads) . '-' . self::pick(self::$namespaceTails)
                . '/v' . random_int(1, 3),
            'return'      => self::pick(self::$returnWords),
            'cancel'      => self::pick(self::$cancelWords),
            'capture'     => self::pick(self::$captureWords),
            'notify'      => self::pick(self::$notifyWords),
            'token_param' => self::pick(self::$tokenParams),
        ];
    }

    /**
     * @param string[] $list
     * @return string
     */
    private static function pick(array $list)
    {
        return $list[random_int(0, count($list) - 1)];
    }
}
