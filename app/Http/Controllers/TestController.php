<?php

namespace App\Http\Controllers;

use App\Services\DeepSeekService;
use Illuminate\Http\Request;

class TestController extends Controller
{
    protected $deepSeek;

    public function __construct(DeepSeekService $deepSeek)
    {
        $this->deepSeek = $deepSeek;
    }

    public function testAI()
    {
        try {
            $response = $this->deepSeek->chat([
                ['role' => 'system', 'content' => 'Ты помощник. Ответь одним предложением.'],
                ['role' => 'user', 'content' => 'Привет! Как дела?']
            ]);

            return response()->json([
                'success' => true,
                'response' => $response
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}