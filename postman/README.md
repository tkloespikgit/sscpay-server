# Postman 测试模板

导入同目录 Collection 和 Environment，选中环境后填写 app_id / api_key / group_key 及回跳地址。base_url 必须包含 /api，末尾不加斜杠。完整协议见 [商户 API](../doc/api.md)，中英文网页位于 /docs/api/zh_CN 与 /docs/api/en。

所有商户请求均为 POST JSON。查询编号也放在已签名 body 内，不使用 GET 查询参数。Collection 脚本先替换环境变量，再对完整 body 去掉 sign、递归规范化，计算 HMAC-SHA256；App-ID / Timestamp / X-Nonce 放 Header，sign 放 body。金额保留十进制字符串。

先运行「创建订单」，脚本保存原请求及返回编号。网络超时或 502 后运行「重试原建单」，保留原商户单号、金额和币种；不要重新 AUTO 建单。正常查询使用已保存的 last_order_no 或 last_merchant_order_no。

物流上传把允许发货的真实订单号填入 shipping_merchant_order_no。上传成功表示本地保存，远端同步状态另查；普通订单和组合子单都调用同一接口。

## 组合付款联调

支付组启用组合付款前，商户先适配 V2 通知。默认跳过组合文件夹；仅在隔离测试环境设置 enable_combined_examples=true 后单独运行：

1. 为测试支付组开启组合付款，填写不低于 4000 USD 的组合订单最高金额，并配置组内全部渠道单笔限额小于 4000 USD。组合候选须为 MATCH/VIRTUAL，且日/月余额需容纳完整单笔上限。COPY/CREATE 不参与组合付款，包括余额一次付清。超出组内组合上限返回 422 / COMBINE_AMOUNT_LIMIT_EXCEEDED；应用通知版本仅控制普通订单，组合订单固定 V2。
2. 创建组合父单，确认 order_type=combine、children=[]，打开 pay_url 进入仅英文的独立收银台；全部付清后只显示完成提示，不提供返回商户链接或自动回跳。
3. 每次只付款当前子单；后续优先沿用原渠道，仅不可用时重选，报价过期也不无故换渠道。第一笔确认后查询应为 status=pending、order_status=partially_paid，累计金额增长。全部付清才为 paid。
4. 校验 webhook 的完整 JSON（包含 children、null 和累计字段）；按 event_id 去重并按 version 防止旧重试覆盖新进度。不能只用父单号 + pending 去重。
5. 全部付清后，逐个 children[].merchant_order_no 上传真实物流。父单号返回 CHILD_ORDER_REQUIRED，部分付款返回 COMBINE_ORDER_NOT_PAID。同一个真实运单涉及多笔子单时，支付插件还需支持跨订单同运单关联。

模拟支付通知还需 enable_webhook_examples=true，且 notify_url 必须是商户测试端点；示例父子编号和金额必须按测试数据修改。网关模拟 paid 会触发业务记账，只能对隔离测试单使用。外部物流示例还需 enable_fulfillment_examples=true 和接收站点凭证，勿指向真实订单。

Collection 不内置真实凭证，不在浏览器或文档中公开 API Key；可通过临时修改测试 api_key 验证 401，测试后恢复。
