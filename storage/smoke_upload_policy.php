<?php

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Models\LogisticsImportTask;
use App\Models\User;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Livewire\Livewire;

Filament::setCurrentPanel(Filament::getPanel('admin'));

$ref = new ReflectionMethod(ListOrders::class, 'getHeaderActions');
$ref->setAccessible(true);

$actionsOf = function (User $user) use ($ref) {
    Livewire::actingAs($user);
    $page = Livewire::test(ListOrders::class)->instance();
    $map = [];
    foreach ($ref->invoke($page) as $action) {
        $map[$action->getName()] = $action->isVisible();
    }

    return $map;
};

$superAdmin = User::query()->where('is_super_admin', true)->firstOrFail();
$merchantUser = User::query()->where('is_super_admin', false)->whereNotNull('merchant_id')->firstOrFail();

echo 'super admin #'.$superAdmin->id.' => '.json_encode($actionsOf($superAdmin)).PHP_EOL;
echo 'merchant user #'.$merchantUser->id.' (m'.$merchantUser->merchant_id.', can='.var_export($merchantUser->can(Permissions::LOGISTICS_IMPORTS_MANAGE), true).') => '
    .json_encode($actionsOf($merchantUser)).PHP_EOL;

// 超管手工构造 Livewire 请求调 uploadLogistics：应被 isDisabled() 挡下，且不落任务
Livewire::actingAs($superAdmin);
$before = LogisticsImportTask::query()->withoutGlobalScopes()->count();
$t = Livewire::test(ListOrders::class);
$t->set('tableFilters.merchant_id.value', $merchantUser->merchant_id);
$result = $t->call('mountAction', 'uploadLogistics');
$after = LogisticsImportTask::query()->withoutGlobalScopes()->count();

echo 'super admin mountAction(uploadLogistics): tasks '.$before.' -> '.$after.PHP_EOL;
echo '  mountedActions: '.json_encode($t->get('mountedActions')).PHP_EOL;

// 超管的导出仍然可用（选了商户）
$t2 = Livewire::test(ListOrders::class);
$t2->set('tableFilters.merchant_id.value', $merchantUser->merchant_id);
$t2->call('mountAction', 'exportLogisticsTemplate');
try {
    $t2->assertFileDownloaded();
    echo 'super admin export with merchant filter: PASS'.PHP_EOL;
} catch (\Throwable $e) {
    echo 'super admin export with merchant filter: FAIL -> '.$e->getMessage().PHP_EOL;
}

// 超管未选商户 -> 不下载、不抛异常
$t3 = Livewire::test(ListOrders::class);
$t3->call('mountAction', 'exportLogisticsTemplate');
try {
    $t3->assertNoFileDownloaded();
    echo 'super admin export without merchant filter: PASS (blocked)'.PHP_EOL;
} catch (\Throwable $e) {
    echo 'super admin export without merchant filter: FAIL -> '.$e->getMessage().PHP_EOL;
}
