<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\GameMessage;
use App\Services\GameMasterService;
use App\Services\DiceService;
use App\Events\GameMessageSent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GameMessageController extends Controller
{
    protected $gm;
    protected $dice;

    public function __construct(GameMasterService $gm, DiceService $dice)
    {
        $this->gm = $gm;
        $this->dice = $dice;
        $this->middleware('auth');
    }

    public function index(Request $request, Room $room)
    {
        if (!$room->isUserInRoom(Auth::id())) {
            return response()->json(['error' => 'Вы не в этой комнате'], 403);
        }

        try {
            $after = (int) $request->get('after', 0);

            $messages = $room->gameMessages()
                ->with('user')
                ->where('created_at', '>', date('Y-m-d H:i:s', $after))
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn ($msg) => $this->formatMessage($msg, $room));

            return response()->json($messages);

        } catch (\Exception $e) {
            Log::error('GameMessageController@index error: ' . $e->getMessage());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function store(Request $request, Room $room)
    {
        try {
            $request->validate(['message' => 'required|string|max:2000']);

            $user = Auth::user();

            if (!$room->isUserInRoom($user->id)) {
                return response()->json(['error' => 'Вы не в этой комнате'], 403);
            }

            if ($room->status !== 'playing') {
                return response()->json(['error' => 'Игра ещё не началась'], 400);
            }

            $characterPivot = $room->users()->where('user_id', $user->id)->first()?->pivot;
            if (!$characterPivot || !$characterPivot->character_name) {
                return response()->json(['error' => 'Сначала создайте персонажа'], 400);
            }

            $message = trim($request->input('message'));
            $isRoll = str_starts_with($message, '/roll');

            $userMessageModel = null;
            $systemMessageModel = null;
            $rollData = null;

            if (!$isRoll) {
                $userMessageModel = GameMessage::create([
                    'room_id' => $room->id,
                    'user_id' => $user->id,
                    'role' => 'user',
                    'content' => $message,
                ]);

                broadcast(new GameMessageSent($userMessageModel, $room))->toOthers();
            } else {
                preg_match('/\/roll\s*(\d+)?/', $message, $matches);
                $difficulty = isset($matches[1]) ? (int) $matches[1] : null;

                $rollData = $this->dice->roll($difficulty);

                $systemMessageModel = GameMessage::create([
                    'room_id' => $room->id,
                    'role' => 'system',
                    'content' => $rollData['message'],
                ]);

                broadcast(new GameMessageSent($systemMessageModel, $room))->toOthers();
            }

            $aiMessageModel = $this->gm->processMessage(
                $room,
                $user,
                $message,
                $rollData['message'] ?? null
            );

            broadcast(new GameMessageSent($aiMessageModel, $room))->toOthers();

            return response()->json([
                'success' => true,
                'user_message' => $userMessageModel ? $this->formatMessage($userMessageModel, $room) : null,
                'system_message' => $systemMessageModel ? $this->formatMessage($systemMessageModel, $room) : null,
                'roll' => $rollData,
                'ai_message' => $this->formatMessage($aiMessageModel, $room),
            ]);

        } catch (\Exception $e) {
            Log::error('GameMessageController@store error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function formatMessage(GameMessage $message, Room $room): array
    {
        $userName = match (true) {
            $message->role === 'assistant' => 'Мастер',
            $message->role === 'system' => 'System',
            (bool) $message->user_id => $room->characterNameForUser($message->user_id)
                ?? $message->user?->name
                ?? 'Игрок',
            default => 'System',
        };

        return [
            'id' => $message->id,
            'role' => $message->role,
            'content' => $message->content,
            'user_name' => $userName,
            'user_id' => $message->user_id,
            'created_at' => $message->created_at->timestamp,
        ];
    }
}