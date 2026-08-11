<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\GameMessage;
use App\Models\OocMessage;
use App\Services\GameMasterService;
use App\Events\RoomStatusUpdated;
use App\Events\GameMessageSent;
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
        $userRoomIds = Auth::user()->rooms()->pluck('room_id')->toArray();

        $rooms = Room::with('creator')
            ->withCount('users')
            ->latest()
            ->paginate(10);

        return view('rooms.index', ['rooms' => $rooms, 'userRooms' => $userRoomIds]);
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

        // Без кэша: запрос дешёвый (один pivot-ряд), а кэш здесь был источником
        // бага с "повторной модалкой создания персонажа" из-за протухания/рассинхрона.
        $character = $room->users()
            ->where('user_id', Auth::id())
            ->first()
            ?->pivot;

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

        broadcast(new RoomStatusUpdated($room));

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Вы присоединились к комнате');
    }

    public function leave(Room $room)
    {
        if ($room->status === 'playing') {
            return back()->with('error', 'Нельзя выйти из комнаты во время игры');
        }

        if (!$room->isUserInRoom(Auth::id())) {
            return redirect()->route('rooms.index')
                ->with('error', 'Вы не в этой комнате');
        }

        if ($room->created_by === Auth::id()) {
            return redirect()->route('rooms.destroy.confirm', $room)
                ->with('warning', 'Вы создатель комнаты. Если хотите удалить комнату, используйте удаление.');
        }

        DB::transaction(function () use ($room) {
            GameMessage::where('room_id', $room->id)
                ->where('user_id', Auth::id())
                ->delete();

            $room->users()->detach(Auth::id());
        });

        broadcast(new RoomStatusUpdated($room));

        return redirect()->route('rooms.index')
            ->with('success', 'Вы успешно вышли из комнаты');
    }

    public function confirmDestroy(Room $room)
    {
        if ($room->created_by !== Auth::id()) {
            return redirect()->route('rooms.index')
                ->with('error', 'Только создатель может удалить комнату');
        }

        return view('rooms.confirm-destroy', compact('room'));
    }

    public function destroy(Room $room)
    {
        if ($room->created_by !== Auth::id()) {
            return redirect()->route('rooms.index')
                ->with('error', 'Только создатель может удалить комнату');
        }

        DB::transaction(function () use ($room) {
            GameMessage::where('room_id', $room->id)->delete();
            OocMessage::where('room_id', $room->id)->delete();
            $room->users()->detach();
            $room->delete();
        });

        return redirect()->route('rooms.index')
            ->with('success', 'Комната успешно удалена');
    }

    public function kickAll(Room $room)
    {
        if ($room->created_by !== Auth::id()) {
            return back()->with('error', 'Только создатель может кикнуть всех');
        }

        DB::transaction(function () use ($room) {
            $room->users()->where('user_id', '!=', $room->created_by)->detach();
            $room->update(['status' => 'waiting']);
            GameMessage::where('room_id', $room->id)->delete();
        });

        broadcast(new RoomStatusUpdated($room));

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
            ...$data,
            'max_hp' => $maxHp,
            'current_hp' => $maxHp,
            'armor_class' => $armorClass,
            'is_ready' => true,
        ]);

        broadcast(new RoomStatusUpdated($room));

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Персонаж создан!')
            ->with('character_created', true);
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
        broadcast(new RoomStatusUpdated($room));

        // Раньше intro-сообщение создавалось, но никогда не транслировалось —
        // игроки видели старт игры только после ручного обновления страницы.
        $intro = $gm->generateIntro($room);
        broadcast(new GameMessageSent($intro, $room));

        return redirect()->route('rooms.show', $room)
            ->with('success', 'Игра началась!');
    }

    public function status(Room $room)
    {
        $data = [
            'status' => $room->status,
            'users' => $room->users()->get()->map(function ($user) {
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
        ];

        return response()->json($data);
    }
}