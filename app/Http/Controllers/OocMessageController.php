<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Http\Requests\Chat\StoreOocMessageRequest;
use App\Services\OocChatService;
use Illuminate\Http\Request;

class OocMessageController extends Controller
{
    public function __construct(private OocChatService $chat)
    {
        $this->middleware('auth');
    }

    public function index(Request $request, Room $room)
    {
        $this->authorize('view', $room);

        $after = (int) $request->get('after', 0);

        $messages = $room->oocMessages()
            ->with('user')
            ->where('created_at', '>', date('Y-m-d H:i:s', $after))
            ->orderBy('created_at', 'asc')
            ->get()
            ->map(fn ($msg) => $this->chat->formatMessage($msg));

        return response()->json($messages);
    }

    public function store(StoreOocMessageRequest $request, Room $room)
    {
        $message = $this->chat->sendMessage($room, $request->user()->id, $request->validated()['message']);

        return response()->json([
            'success' => true,
            'message' => $this->chat->formatMessage($message),
        ]);
    }
}