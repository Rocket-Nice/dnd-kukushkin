<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Room;
use Illuminate\Http\Request;

/**
 * @OA\Tag(
 *     name="Rooms",
 *     description="Управление комнатами"
 * )
 */
class RoomController extends Controller
{
    /**
     * @OA\Get(
     *     path="/api/rooms",
     *     summary="Список всех комнат",
     *     tags={"Rooms"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="Успешный ответ",
     *         @OA\JsonContent(
     *             type="array",
     *             @OA\Items(ref="#/components/schemas/Room")
     *         )
     *     )
     * )
     */
    public function index()
    {
        $rooms = Room::with('creator')->withCount('users')->paginate(10);
        return response()->json($rooms);
    }

    /**
     * @OA\Post(
     *     path="/api/rooms",
     *     summary="Создать комнату",
     *     tags={"Rooms"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"name", "max_players"},
     *             @OA\Property(property="name", type="string", example="Моя комната"),
     *             @OA\Property(property="master_prompt", type="string", example="Опишите сюжет..."),
     *             @OA\Property(property="max_players", type="integer", example=4)
     *         )
     *     ),
     *     @OA\Response(
     *         response=201,
     *         description="Комната создана",
     *         @OA\JsonContent(ref="#/components/schemas/Room")
     *     )
     * )
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'master_prompt' => 'nullable|string',
            'max_players' => 'integer|min:2|max:4',
        ]);

        $room = Room::create([
            ...$data,
            'created_by' => auth()->id(),
        ]);

        $room->users()->attach(auth()->id(), ['joined_at' => now()]);

        return response()->json($room, 201);
    }

    /**
     * @OA\Get(
     *     path="/api/rooms/{id}",
     *     summary="Получить информацию о комнате",
     *     tags={"Rooms"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="id",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Комната найдена"),
     *     @OA\Response(response=404, description="Комната не найдена")
     * )
     */
    public function show(Room $room)
    {
        return response()->json($room->load('creator', 'users'));
    }

    /**
     * @OA\Post(
     *     path="/api/rooms/{room}/join",
     *     summary="Присоединиться к комнате",
     *     tags={"Rooms"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="room",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Успешно присоединился"),
     *     @OA\Response(response=400, description="Комната полна или уже в комнате")
     * )
     */
    public function join(Room $room)
    {
        if ($room->isFull()) {
            return response()->json(['error' => 'Комната заполнена'], 400);
        }

        if ($room->isUserInRoom(auth()->id())) {
            return response()->json(['error' => 'Вы уже в комнате'], 400);
        }

        $room->users()->attach(auth()->id(), ['joined_at' => now()]);

        return response()->json(['message' => 'Вы присоединились к комнате']);
    }

    /**
     * @OA\Post(
     *     path="/api/rooms/{room}/start",
     *     summary="Начать игру в комнате",
     *     tags={"Rooms"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Parameter(
     *         name="room",
     *         in="path",
     *         required=true,
     *         @OA\Schema(type="integer")
     *     ),
     *     @OA\Response(response=200, description="Игра началась"),
     *     @OA\Response(response=403, description="Недостаточно прав")
     * )
     */
    public function start(Room $room)
    {
        if ($room->created_by !== auth()->id()) {
            return response()->json(['error' => 'Только создатель может начать игру'], 403);
        }

        $readyCount = $room->users()->wherePivot('is_ready', true)->count();
        if ($readyCount < 2) {
            return response()->json(['error' => 'Нужно минимум 2 готовых игрока'], 400);
        }

        $room->update(['status' => 'playing']);

        return response()->json(['message' => 'Игра началась']);
    }
}
