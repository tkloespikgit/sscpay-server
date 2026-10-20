<?php

namespace App\Http\Controllers;

use App\Filament\Resources\PaymentMethodResource;
use App\Models\PaymentMethodProfile;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\User;
use App\Services\PurchaseOrderService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $purchaseOrders,
    ) {}

    public function show(Request $request, int $purchaseOrder): View
    {
        return view('purchase-orders.show', $this->documentData($request, $purchaseOrder));
    }

    public function details(Request $request, int $purchaseOrder): View
    {
        return view('purchase-orders.details.show', $this->documentData($request, $purchaseOrder));
    }

    /**
     * @return array{
     *     purchaseOrder: PurchaseOrder,
     *     sellerName: string,
     *     buyerName: string,
     *     buyerAccount: string,
     *     paymentMethodUrl: string,
     *     itemImageUrls: array<int, string|null>,
     *     documentStatus: string,
     *     perks_words: array<int, string>,
     *     sellerCompany: string,
     *     detailFields: array{
     *         buyerContactName: string,
     *         buyerCompany: string,
     *         buyerAddress: string,
     *         buyerPhone: string,
     *         buyerTelephone: string,
     *         buyerEmail: string,
     *         sellerMemberName: string,
     *         sellerContactName: string,
     *         sellerPhone: string,
     *         sellerTelephone: string,
     *         sellerAddress: string,
     *         sellerEmail: string,
     *         shippingCompany: string,
     *         trackingNumber: string,
     *         paymentMethodName: string
     *     }
     * }
     */
    private function documentData(Request $request, int $purchaseOrder): array
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->is_super_admin && $user->status && ! $user->trashed(), 403);

        $purchaseOrder = PurchaseOrder::query()
            ->with(['items.siteProduct', 'paymentMethod.profile', 'creator'])
            ->findOrFail($purchaseOrder);
        $perksWords = $this->purchaseOrders->initializePerksWords($purchaseOrder, $user);

        $paymentMethod = $purchaseOrder->paymentMethod;
        $profile = $paymentMethod?->profile;
        $sellerCompany = trim((string) $profile?->supplier_name) ?: '未设置供应商';
        $sellerName = trim((string) $profile?->supplier_contact_person)
            ?: trim((string) $paymentMethod?->method_name)
            ?: __('purchase_order.document.supplier_unset');
        $buyerName = trim((string) $profile?->legal_representative_name) ?: __('purchase_order.document.buyer_unset');
        $buyerAccount = trim(User::accountFromEmail($purchaseOrder->creator?->email)) ?: $buyerName;
        $paymentMethodUrl = $paymentMethod !== null && ! $paymentMethod->trashed()
            ? PaymentMethodResource::getUrl('edit', ['record' => $paymentMethod->getKey()], panel: 'admin')
            : PaymentMethodResource::getUrl('index', panel: 'admin');
        $itemImageUrls = $purchaseOrder->items->mapWithKeys(fn (PurchaseOrderItem $item): array => [
            $item->getKey() => $this->safeImageUrl($item->siteProduct?->image_url),
        ])->all();

        $notSet = __('purchase_order.detail.unset');

        return [
            'purchaseOrder' => $purchaseOrder,
            'sellerName' => $sellerName,
            'buyerName' => $buyerName,
            'buyerAccount' => $buyerAccount,
            'paymentMethodUrl' => $paymentMethodUrl,
            'itemImageUrls' => $itemImageUrls,
            'documentStatus' => __('purchase_order.document.status_generated'),
            'perks_words' => $perksWords,
            'sellerCompany' => $sellerCompany,
            'detailFields' => [
                'buyerContactName' => trim((string) $profile?->legal_representative_name) ?: $notSet,
                'buyerCompany' => trim((string) $profile?->company_name) ?: $notSet,
                'buyerAddress' => $this->buyerAddress($profile),
                'buyerPhone' => trim((string) $profile?->legal_representative_phone) ?: $notSet,
                'buyerTelephone' => $notSet,
                'buyerEmail' => trim((string) $profile?->account_email) ?: $notSet,
                'sellerMemberName' => trim((string) $profile?->supplier_name) ?: $notSet,
                'sellerContactName' => trim((string) $profile?->supplier_contact_person) ?: $notSet,
                'sellerPhone' => trim((string) $profile?->supplier_phone) ?: $notSet,
                'sellerTelephone' => $notSet,
                'sellerAddress' => trim((string) $profile?->supplier_address) ?: $notSet,
                'sellerEmail' => trim((string) $profile?->supplier_email) ?: $notSet,
                'shippingCompany' => $notSet,
                'trackingNumber' => $notSet,
                'paymentMethodName' => trim((string) $paymentMethod?->method_name) ?: $notSet,
            ],
        ];
    }

    private function buyerAddress(?PaymentMethodProfile $profile): string
    {
        foreach ((array) $profile?->stock_addresses as $address) {
            if (is_string($address) && trim($address) !== '') {
                return trim($address);
            }
        }

        return trim((string) $profile?->company_address) ?: __('purchase_order.detail.unset');
    }

    private function safeImageUrl(?string $url): ?string
    {
        if ($url === null || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return null;
        }

        $parts = parse_url($url);

        if ($parts === false
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || empty($parts['host'])
            || isset($parts['user'])
            || isset($parts['pass'])) {
            return null;
        }

        return $url;
    }
}
