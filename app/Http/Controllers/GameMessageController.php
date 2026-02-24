<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\GameMessage;
use App\Services\GameMasterService;
use App\Services\DiceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

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
        try {
            $after = $request->get('after', 0);
            $timestamp = date('Y-m-d H:i:s', $after);
            
            $messages = $room->gameMessages()
                ->with('user')
                ->where('created_at', '>', $timestamp)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(function ($msg) use ($room) {
                    $userName = 'System';
                    
                    if ($msg->role === 'assistant') {
                        $userName = 'Мастер';
                    } elseif ($msg->role === 'system') {
                        $userName = 'System';
                    } elseif ($msg->user) {
                        // Получаем имя персонажа напрямую из таблицы room_user
                        $pivotData = DB::table('room_user')
                            ->where('room_id', $room->id)
                            ->where('user_id', $msg->user_id)
                            ->first();
                        
                        if ($pivotData && !empty($pivotData->character_name)) {
                            $userName = $pivotData->character_name;
                        } else {
                            $userName = $msg->user->name;
                        }
                    }
                    
                    return [
                        'id' => $msg->id,
                        'role' => $msg->role,
                        'content' => $msg->content,
                        'user_name' => $userName,
                        'user_id' => $msg->user_id,
                        'created_at' => $msg->created_at->timestamp,
                    ];
                });

            return response()->json($messages);
            
        } catch (\Exception $e) {
            Log::error('GameMessageController@index error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['error' => 'Server error'], 500);
        }
    }

    public function store(Request $request, Room $room)
    {
        try {
            $request->validate(['message' => 'required|string']);

            $message = $request->input('message');
            $user = Auth::user();

            // Проверка наличия персонажа
            $character = $room->users()->where('user_id', $user->id)->first();
            if (!$character) {
                return response()->json(['error' => 'Сначала создайте персонажа'], 400);
            }

            $characterData = $character->pivot;

            // Сохраняем сообщение пользователя
            $userMessage = GameMessage::create([
                'room_id' => $room->id,
                'user_id' => $user->id,
                'role' => 'user',
                'content' => $message,
            ]);

            // Обработка броска кубика
            if (str_starts_with($message, '/roll')) {
                preg_match('/\/roll\s*(\d+)?/', $message, $matches);
                $difficulty = isset($matches[1]) ? (int)$matches[1] : null;

                $roll = $this->dice->roll($difficulty);

                // Сохраняем результат броска
                GameMessage::create([
                    'room_id' => $room->id,
                    'role' => 'system',
                    'content' => $roll['message'],
                ]);

                // Отправляем в AI с результатом
                $aiResponse = $this->gm->processMessage($room, $user, $message, $roll['message']);

                return response()->json([
                    'success' => true,
                    'roll' => $roll,
                    'ai_message' => $aiResponse->content,
                    'user_name' => $characterData->character_name ?? $user->name,
                ]);
            }

            // Обычное сообщение
            $aiResponse = $this->gm->processMessage($room, $user, $message);

            return response()->json([
                'success' => true,
                'user_message' => $userMessage->content,
                'user_name' => $characterData->character_name ?? $user->name,
                'ai_message' => $aiResponse->content,
            ]);

        } catch (\Exception $e) {
            Log::error('GameMessageController@store error: ' . $e->getMessage());
            Log::error($e->getTraceAsString());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}