<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\GameMessage;
use App\Models\OocMessage;
use App\Services\GameMasterService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class RoomController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        // Очищаем кэш пользователя перед загрузкой списка
        cache()->forget("user_" . Auth::id() . "_rooms");
        
        $rooms = Room::with('creator')
            ->withCount('users')
            ->latest()
            ->paginate(10);
        
        // Добавляем информацию о том, в каких комнатах находится пользователь
        $userRooms = Auth::user()->rooms()->pluck('room_id')->toArray();
        
        return view('rooms.index', compact('rooms', 'userRooms'));
    }

    public function create()
    {
        return view('rooms.create');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'master_prompt' => 'nullable|string',
            'max_players' => 'integer|min:2|max:4',
        ]);

        $room = Room::create([
            ...$data,
            'created_by' => Auth::id(),
        ]);

        $room->users()->attach(Auth::id(), ['joined_at' => now()]);

        return redirect()->route('rooms.show', ['room' => $room->id])
            ->with('success', 'Комната создана!');
    }

    public function show(Room $room)
    {
        if (!$room->isUserInRoom(Auth::id())) {
            return redirect()->route('rooms.index')
                ->with('error', 'Вы не присоединились к этой комнате');
        }

        $character = $room->users()
            ->where('user_id', Auth::id())
            ->first()
            ?->pivot;

        // Если персонаж не создан - показываем модалку
        if (!$character || !$character->character_name) {
            return view('rooms.show', compact('room', 'character'));
        }

        return view('rooms.show', compact('room', 'character'));
    }

    public function join(Room $room)
    {
        if ($room->isFull()) {
            return back()->with('error', 'Комната заполнена');
        }

        if ($room->isUserInRoom(Auth::id())) {
            return back()->with('error', 'Вы уже в комнате');
        }

        $room->users()->syncWithoutDetaching([Auth::id() => ['joined_at' => now()]]);

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Вы присоединились к комнате');
    }

    /**
     * Выход из комнаты
     */
    /**
 * Выход из комнаты
 */
    public function leave(Room $room)
    {
        // Нельзя выйти, если игра уже началась
        if ($room->status === 'playing') {
            return back()->with('error', 'Нельзя выйти из комнаты во время игры');
        }

        // Проверяем, что пользователь в комнате
        if (!$room->isUserInRoom(Auth::id())) {
            return redirect()->route('rooms.index')
                ->with('error', 'Вы не в этой комнате');
        }

        // Если пользователь - создатель комнаты, перенаправляем на удаление
        if ($room->created_by === Auth::id()) {
            return redirect()->route('rooms.destroy.confirm', $room)
                ->with('warning', 'Вы создатель комнаты. Если хотите удалить комнату, используйте удаление.');
        }

        // Используем транзакцию для гарантии
        DB::transaction(function () use ($room) {
            // Удаляем все сообщения пользователя в этой комнате (опционально)
            GameMessage::where('room_id', $room->id)
                ->where('user_id', Auth::id())
                ->delete();
            
            // Удаляем персонажа пользователя из комнаты
            $room->users()->detach(Auth::id());
        });

        // Очищаем кэш
        cache()->forget("room_{$room->id}_users");
        cache()->forget("room_{$room->id}_data");
        cache()->forget("user_" . Auth::id() . "_rooms");

        // Перенаправляем на список комнат с сообщением
        return redirect()->route('rooms.index')
            ->with('success', 'Вы успешно вышли из комнаты');
    }

    /**
     * Страница подтверждения удаления комнаты
     */
    public function confirmDestroy(Room $room)
    {
        // Только создатель может удалять комнату
        if ($room->created_by !== Auth::id()) {
            return redirect()->route('rooms.index')
                ->with('error', 'Только создатель может удалить комнату');
        }

        return view('rooms.confirm-destroy', compact('room'));
    }

    /**
     * Удаление комнаты
     */
    public function destroy(Room $room)
    {
        // Только создатель может удалять комнату
        if ($room->created_by !== Auth::id()) {
            return redirect()->route('rooms.index')
                ->with('error', 'Только создатель может удалить комнату');
        }

        // Используем транзакцию для гарантии целостности данных
        DB::transaction(function () use ($room) {
            // Удаляем все сообщения (они удалятся каскадно, но для надежности)
            GameMessage::where('room_id', $room->id)->delete();
            OocMessage::where('room_id', $room->id)->delete();
            
            // Отсоединяем всех пользователей
            $room->users()->detach();
            
            // Удаляем комнату
            $room->delete();
        });

        // Очищаем кэш
        $this->clearRoomCache($room->id);

        return redirect()->route('rooms.index')
            ->with('success', 'Комната успешно удалена');
    }

    /**
     * Очистка кэша комнаты
     */
    private function clearRoomCache($roomId)
    {
        try {
            // Очищаем возможные кэшированные данные
            cache()->forget("room_{$roomId}_users");
            cache()->forget("room_{$roomId}_messages");
            cache()->forget("room_{$roomId}_game_state");
            
            // Если используется view cache
            \Artisan::call('view:clear');
            
            // Если используется config cache
            \Artisan::call('config:clear');
            
            // Если используется route cache
            \Artisan::call('route:clear');
            
        } catch (\Exception $e) {
            \Log::error('Error clearing cache: ' . $e->getMessage());
        }
    }

    /**
     * Принудительный выход всех игроков (для админа или при баге)
     */
    public function kickAll(Room $room)
    {
        if ($room->created_by !== Auth::id()) {
            return back()->with('error', 'Только создатель может кикнуть всех');
        }

        DB::transaction(function () use ($room) {
            // Удаляем всех пользователей кроме создателя
            $room->users()->where('user_id', '!=', $room->created_by)->detach();
            
            // Сбрасываем статус комнаты
            $room->update(['status' => 'waiting']);
            
            // Удаляем все игровые сообщения
            GameMessage::where('room_id', $room->id)->delete();
        });

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Все игроки были удалены из комнаты');
    }

    public function saveCharacter(Request $request, Room $room)
    {
        $data = $request->validate([
            'character_name' => 'required|string|max:255',
            'character_description' => 'nullable|string',
            'character_class' => 'required|string',
            'strength' => 'required|integer|min:3|max:20',
            'dexterity' => 'required|integer|min:3|max:20',
            'constitution' => 'required|integer|min:3|max:20',
            'intelligence' => 'required|integer|min:3|max:20',
            'wisdom' => 'required|integer|min:3|max:20',
            'charisma' => 'required|integer|min:3|max:20',
        ]);

        $modCon = floor(($data['constitution'] - 10) / 2);
        $maxHp = 30 + $modCon * 2;

        $modDex = floor(($data['dexterity'] - 10) / 2);
        $armorClass = 10 + $modDex;

        $room->users()->updateExistingPivot(Auth::id(), [
            'character_name' => $data['character_name'],
            'character_description' => $data['character_description'],
            'character_class' => $data['character_class'],
            'strength' => $data['strength'],
            'dexterity' => $data['dexterity'],
            'constitution' => $data['constitution'],
            'intelligence' => $data['intelligence'],
            'wisdom' => $data['wisdom'],
            'charisma' => $data['charisma'],
            'max_hp' => $maxHp,
            'current_hp' => $maxHp,
            'armor_class' => $armorClass,
            'is_ready' => true,
        ]);

        // Очищаем кэш пользователей комнаты
        cache()->forget("room_{$room->id}_users");

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Персонаж создан!');
    }

    public function start(Room $room, GameMasterService $gm)
    {
        if ($room->created_by !== Auth::id()) {
            return back()->with('error', 'Только создатель может начать игру');
        }

        $readyCount = $room->users()->wherePivot('is_ready', true)->count();
        if ($readyCount < 2) {
            return back()->with('error', 'Нужно минимум 2 готовых игрока');
        }

        $room->update(['status' => 'playing']);
        
        $gm->generateIntro($room);

        // Очищаем кэш
        cache()->forget("room_{$room->id}_game_state");

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Игра началась!');
    }

/**
 * Получить статус комнаты
 */
    public function status(Room $room)
    {
        return response()->json([
            'status' => $room->status,
            'users' => $room->users()->get()->map(function($user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'character_name' => $user->pivot->character_name,
                    'is_ready' => $user->pivot->is_ready,
                    'current_hp' => $user->pivot->current_hp,
                    'max_hp' => $user->pivot->max_hp,
                    'armor_class' => $user->pivot->armor_class,
                ];
            }),
            'users_count' => $room->users()->count(),
            'ready_count' => $room->users()->wherePivot('is_ready', true)->count(),
        ]);
    }
}