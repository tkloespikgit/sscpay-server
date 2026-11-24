<?php

namespace App\Http\Controllers;

use App\Services\ChargebackAlerts\ChargebackAlertDrivers;
use App\Services\ChargebackAlerts\ChargebackAlertInbox;
use App\Services\ChargebackAlerts\WebhookEnvelope;
use App\Services\ChargebackAlerts\WebhookRejected;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class ChargebackWebhookController extends Controller
{
    public function __invoke(Request $request, string $provider, ChargebackAlertDrivers $drivers, ChargebackAlertInbox $inbox): Response
    {
        $driver = $drivers->find($provider);
        abort_if($driver === null, 404);

        $error = null;
        try {
            $body = $request->getContent();
            if ($body === '' || strlen($body) > 128 * 1024) {
                throw new WebhookRejected;
            }

            $inbox->receive($driver, new WebhookEnvelope($body, $request->headers->all()));
        } catch (WebhookRejected $rejected) {
            $error = $rejected;
        } catch (Throwable $exception) {
            Log::error('Chargeback webhook could not be stored.', ['provider' => $provider, 'exception' => $exception::class]);
            $error = new WebhookRejected(500);
        }

        $response = $driver->acknowledgement($error);

        return is_array($response['body'])
            ? response()->json($response['body'], $response['status'])
            : response($response['body'], $response['status']);
    }
}
