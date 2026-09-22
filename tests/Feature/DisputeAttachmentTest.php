<?php

namespace Tests\Feature;

use App\Filament\Resources\OrderDisputeEventResource\Pages\ViewOrderDisputeEvent;
use App\Models\Merchant;
use App\Models\Order;
use App\Models\OrderDisputeEvent;
use App\Models\User;
use App\Support\DisputeAttachments;
use App\Support\Permissions;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\CreatesTestOrders;
use Tests\TestCase;

/**
 * 争议审核事件的图片凭证：详情页要渲染出灯箱（放大 + 下载），
 * 下载路由要挡住未登录、无权限和跨商户的访问。
 */
class DisputeAttachmentTest extends TestCase
{
    use CreatesTestOrders;
    use RefreshDatabase;

    private const IMAGE_PATH = 'merchants/1/dispute-events/2026-09-22/evidence.jpg';

    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('oss');

        $this->createMerchantAndApplication();
        $this->order = $this->makeOrder('SSC-1', 'M-1', ['status' => 'paid']);
    }

    public function test_detail_page_renders_lightbox_with_download_link(): void
    {
        $event = $this->makeEvent([self::IMAGE_PATH]);

        Filament::setCurrentPanel('admin');

        // 下载地址是 JSON 编码进 Alpine 的 x-data 里的（斜杠被转义），
        // 所以断言拆成"路由前缀 + download 标记"，不比对完整 URL 字符串。
        Livewire::actingAs($this->superAdmin())
            ->test(ViewOrderDisputeEvent::class, ['record' => $event->getKey()])
            ->assertSee('dispute-attachments')
            ->assertSee('download=1')
            ->assertSee(__('admin.order_dispute_event.fields.images'))
            ->assertSee(__('admin.order_dispute_event.images_viewer.download'));

        $this->assertSame(
            $this->url($event, download: true),
            DisputeAttachments::for($event)[0]['download'],
        );
    }

    public function test_download_streams_the_file_as_an_attachment(): void
    {
        Storage::disk('oss')->put(self::IMAGE_PATH, 'fake-image-bytes');

        $event = $this->makeEvent([self::IMAGE_PATH]);

        $response = $this->actingAs($this->superAdmin())->get($this->url($event, download: true));

        $response->assertOk();
        $this->assertStringContainsString('attachment', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('evidence.jpg', $response->headers->get('content-disposition'));
        $this->assertSame('fake-image-bytes', $response->streamedContent());
    }

    public function test_inline_preview_does_not_force_a_download(): void
    {
        Storage::disk('oss')->put(self::IMAGE_PATH, 'fake-image-bytes');

        $event = $this->makeEvent([self::IMAGE_PATH]);

        $response = $this->actingAs($this->superAdmin())->get($this->url($event));

        $response->assertOk();
        $this->assertStringNotContainsString('attachment', (string) $response->headers->get('content-disposition'));
    }

    public function test_guests_cannot_download(): void
    {
        Storage::disk('oss')->put(self::IMAGE_PATH, 'fake-image-bytes');

        $event = $this->makeEvent([self::IMAGE_PATH]);

        $this->get($this->url($event, download: true))->assertForbidden();
    }

    public function test_another_merchants_user_gets_404(): void
    {
        Storage::disk('oss')->put(self::IMAGE_PATH, 'fake-image-bytes');

        $event = $this->makeEvent([self::IMAGE_PATH]);

        $other = Merchant::create([
            'name' => 'Other Merchant',
            'contact_person' => 'Other',
            'contact_phone' => '654321',
            'contact_email' => 'other@example.com',
        ]);

        $outsider = User::create([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $other->id,
        ]);
        $outsider->givePermissionTo(Permissions::ORDER_DISPUTES_VIEW);

        // MerchantScope 把记录挡在查询之外，所以是 404 而不是 403
        $this->actingAs($outsider)->get($this->url($event, download: true))->assertNotFound();
    }

    public function test_user_without_dispute_permission_is_forbidden(): void
    {
        Storage::disk('oss')->put(self::IMAGE_PATH, 'fake-image-bytes');

        $event = $this->makeEvent([self::IMAGE_PATH]);

        $user = User::create([
            'name' => 'No Permission',
            'email' => 'nope@example.com',
            'password' => bcrypt('secret'),
            'merchant_id' => $this->merchant->id,
        ]);

        $this->actingAs($user)->get($this->url($event, download: true))->assertForbidden();
    }

    public function test_out_of_range_index_is_404(): void
    {
        $event = $this->makeEvent([self::IMAGE_PATH]);

        $this->actingAs($this->superAdmin())
            ->get($this->url($event, index: 7, download: true))
            ->assertNotFound();
    }

    private function url(OrderDisputeEvent $event, int $index = 0, bool $download = false): string
    {
        return route('dispute-attachments.show', array_filter([
            'type' => 'event',
            'record' => $event->getKey(),
            'index' => $index,
            'download' => $download ? 1 : null,
        ], fn ($value) => $value !== null));
    }

    private function superAdmin(): User
    {
        return User::firstOrCreate(
            ['email' => 'root@example.com'],
            ['name' => 'Root', 'password' => bcrypt('secret'), 'is_super_admin' => true],
        );
    }

    private function makeEvent(array $images): OrderDisputeEvent
    {
        return OrderDisputeEvent::create([
            'merchant_id' => $this->merchant->id,
            'order_id' => $this->order->id,
            'order_no' => $this->order->order_no,
            'event_no' => 'CASE-1',
            'status' => OrderDisputeEvent::STATUS_PROCESSING,
            'reason' => '<p>客户申诉</p>',
            'images' => $images,
            'final_action' => OrderDisputeEvent::FINAL_ACTION_REFUND,
            'deadline_value' => 3,
            'deadline_unit' => OrderDisputeEvent::DEADLINE_UNIT_DAYS,
            'deadline_hours' => 72,
            'frozen_amount' => '100.00',
            'opened_by' => $this->superAdmin()->id,
            'opened_at' => now(),
            'due_at' => now()->addDays(3),
        ]);
    }
}
