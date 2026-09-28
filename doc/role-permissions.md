# 商户默认角色权限分配

## 使用入口

平台超级管理员登录后，进入 **平台管理 → 角色权限分配**（`/admin/merchant-role-permissions`）。用户管理列表顶部也提供“角色权限分配”按钮。

1. 展开需要调整的默认角色，勾选允许的操作。
2. 点击 **保存并同步所有商户**。
3. 保存成功后显示已同步的商户数量。商户用户刷新后台页面即可使用更新后的权限，无需重新绑定角色。

默认支持六种角色：商户管理员、订单管理员、物流管理员、网站应用管理员、财务管理员、支付通道管理员。订单管理员的内置模板现在包含 **管理收款链接** 和 **管理与验证自有域名**。

存量商户需要超级管理员首次点击一次保存，才会同步到新模板；新增商户自动使用当前模板。此功能复用已有 `system_configs`，不需要新增迁移或 `.env` 配置。

## 同步范围

- 保存覆盖所有未删除商户的同名默认角色权限，停用商户也包含在内；缺少的默认角色自动补建。
- 同步保持已有角色 ID 和用户角色绑定。商户自建/已改名的角色、用户单独授予的权限、平台共享的“商户级管理员”角色不参与覆盖。
- 权限是叠加的：从默认角色取消某权限后，如果用户仍从另一个角色或直接授权获得它，该用户仍能操作。
- 只能分配 `Permissions::merchantScoped()` 中的商户权限；平台权限不会出现在选项中，提交伪造值也会被拒绝。
- 超管专属页面在菜单、首次访问与后续保存请求中均检查身份及平台面板，商户级管理员不能通过直接访问 URL 使用它。
- 模板和商户角色在一个数据库事务中更新，失败时回滚；完成后清理 Spatie 权限缓存。同步按每批 100 个商户读取，并使用同一配置行锁协调新商户开通及同步。

## 开发入口

| 代码 | 职责 |
|---|---|
| `app/Filament/Pages/MerchantRolePermissions.php` | 超管页面、权限勾选与保存操作 |
| `resources/views/filament/pages/merchant-role-permissions.blade.php` | 表单及保存按钮 |
| `app/Services/MerchantRoleProvisioningService.php` | 内置模板、持久化、校验、同步与新商户开通 |
| `app/Observers/MerchantObserver.php` | 新商户创建时应用模板 |
| `app/Support/Permissions.php` | 可分配权限白名单 |
| `app/Filament/Resources/SystemConfigResource.php` | 排除模板配置，防止通过普通配置页修改后漏同步 |

模板保存在 `system_configs.config_key = permissions.merchant_role_templates`，JSON 结构为 `角色键 → 权限字符串数组`。服务直接读取这条配置，不使用小时缓存；固定角色键与数据库中文角色名的映射由服务定义，界面标签有中英文翻译。

`php artisan merchants:provision-roles` 会应用已保存模板。仅需给旧环境增量补发收款链接权限时，可运行 `php artisan permissions:rollout-checkout-links`：现在包含订单管理员，并尊重超管模板中已取消的这两项权限。

## 验证

相关回归测试：

```bash
php artisan test --filter=MerchantRolePermissionsTest
php artisan test --filter=MerchantDomainResourceTest
php artisan test --filter=CheckoutLinkFormTest
```

覆盖模板保存/重新读取、现有与新商户同步、权限新增/撤销、用户绑定和自建角色保留、非法提交、身份限制、事务回滚，以及订单管理员创建收款链接、管理与验证域名和跨商户隔离。
