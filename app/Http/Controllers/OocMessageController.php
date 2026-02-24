<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\OocMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class OocMessageController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index(Request $request, Room $room)
    {
        $after = $request->get('after', 0);
        
        $messages = $room->oocMessages()
            ->with('user')
            ->where('created_at', '>', date('Y-m-d H:i:s', $after))
            ->get()
            ->map(function ($msg) {
                return [
                    'id' => $msg->id,
                    'content' => $msg->content,
                    'user_name' => $msg->user->name,
                    'user_id' => $msg->user_id,
                    'created_at' => $msg->created_at->timestamp,
                ];
            });

        return response()->json($messages);
    }

    public function store(Request $request, Room $room)
    {
        $request->validate(['message' => 'required|string']);

        $message = OocMessage::create([
            'room_id' => $room->id,
            'user_id' => Auth::id(),
            'content' => $request->message,
        ]);

        $message->load('user');

        return response()->json([
            'success' => true,
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'user_name' => $message->user->name,
                'user_id' => $message->user_id,
                'created_at' => $message->created_at->timestamp,
            ]
        ]);
    }
}