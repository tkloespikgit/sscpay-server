<?php

namespace App\Http\Controllers;

use App\Services\TelegramBindingService;
use App\Services\TelegramBotClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

class TelegramWebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request, TelegramBindingService $service): JsonResponse
    {
        abort_unless(TelegramBotClient::platformIsConfigured(), 503);
        abort_unless(hash_equals(
            (string) config('services.telegram.webhook_secret'),
            (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''),
        ), 403);

        $validator = Validator::make($request->only('message'), [
            'message.chat.id' => ['required', 'integer', 'not_in:0'],
            'message.chat.type' => ['required', 'in:private,group,supergroup'],
            'message.chat.title' => ['sometimes', 'string', 'max:255'],
            'message.chat.first_name' => ['sometimes', 'string', 'max:255'],
            'message.chat.last_name' => ['sometimes', 'string', 'max:255'],
            'message.chat.username' => ['sometimes', 'string', 'max:255'],
            'message.from.id' => ['required', 'integer', 'min:1'],
            'message.from.is_bot' => ['required', 'declined'],
            'message.text' => ['required', 'string', 'max:200'],
        ]);

        if ($validator->fails()) {
            return response()->json(['ok' => true]);
        }

        $message = $validator->validated()['message'];

        try {
            $reply = $service->handle($message);
        } catch (RuntimeException) {
            return response()->json(['ok' => false], 503);
        }

        return response()->json($reply === null ? ['ok' => true] : [
            'method' => 'sendMessage',
            'chat_id' => (string) $message['chat']['id'],
            'text' => $reply,
        ]);
    }
}
