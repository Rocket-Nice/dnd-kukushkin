<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Http\Requests\Chat\StoreGameMessageRequest;
use App\Services\GameChatService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class GameMessageController extends Controller
{
    public function __construct(private GameChatService $chat)
    {
        $this->middleware('auth');
    }

    public function index(Request $request, Room $room)
    {
        $this->authorize('view', $room);

        try {
            $after = (int) $request->get('after', 0);

            $messages = $room->gameMessages()
                ->with('user')
                ->where('created_at', '>', date('Y-m-d H:i:s', $after))
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn ($msg) => $this->chat->formatMessage($msg, $room));

            return response()->json($messages);

        } catch (\Exception $e) {
            Log::error('GameMessageController@index error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function store(StoreGameMessageRequest $request, Room $room)
    {
        try {
            $result = $this->chat->sendMessage($room, $request->user(), $request->validated()['message']);

            return response()->json([
                'success' => true,
                'user_message' => $result['user_message'] ? $this->chat->formatMessage($result['user_message'], $room) : null,
                'system_message' => $result['system_message'] ? $this->chat->formatMessage($result['system_message'], $room) : null,
                'roll' => $result['roll'],
                'ai_message' => $this->chat->formatMessage($result['ai_message'], $room),
                'stat_changes' => $result['stat_changes'],
            ]);

        } catch (RuntimeException $e) {
            // Ожидаемые бизнес-ошибки (например "сначала создайте персонажа")
            return response()->json(['error' => $e->getMessage()], 400);
        } catch (\Throwable $e) {
            Log::error('GameMessageController@store error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['error' => 'Мастер временно недоступен, попробуйте ещё раз'], 502);
        }
    }
}