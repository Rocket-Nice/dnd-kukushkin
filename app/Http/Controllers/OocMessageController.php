<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\OocMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use App\Events\OocMessageSent;

class OocMessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request, Room $room)
    {
        if (!$room->isUserInRoom(Auth::id())) {
            return response()->json(['error' => 'Вы не в этой комнате'], 403);
        }

        $after = (int) $request->get('after', 0);

        $messages = $room->oocMessages()
            ->with('user')
            ->where('created_at', '>', date('Y-m-d H:i:s', $after))
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($msg) => $this->formatMessage($msg));

        return response()->json($messages);
    }

    public function store(Request $request, Room $room)
    {
        $request->validate(['message' => 'required|string|max:1000']);

        if (!$room->isUserInRoom(Auth::id())) {
            return response()->json(['error' => 'Вы не в этой комнате'], 403);
        }

        $message = OocMessage::create([
            'room_id' => $room->id,
            'user_id' => Auth::id(),
            'content' => trim($request->message),
        ]);

        $message->load('user');

        broadcast(new OocMessageSent($message, $room))->toOthers();

        return response()->json([
            'success' => true,
            'message' => $this->formatMessage($message),
        ]);
    }

    private function formatMessage(OocMessage $msg): array
    {
        return [
            'id' => $msg->id,
            'content' => $msg->content,
            'user_name' => $msg->user->name,
            'user_id' => $msg->user_id,
            'created_at' => $msg->created_at->timestamp,
        ];
    }
}