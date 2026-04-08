<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

/**
 * @OA\Info(
 *     title="D&D Game API",
 *     version="1.0.0",
 *     description="API для D&D игры с ИИ-мастером"
 * )
 * 
 * @OA\Server(
 *     url="http://localhost:8000/api",
 *     description="Локальный сервер"
 * )
 * 
 * @OA\Server(
 *     url="https://dnd-game.com/api",
 *     description="Продакшен сервер"
 * )
 * 
 * @OA\Components(
 *     @OA\SecurityScheme(
 *         securityScheme="bearerAuth",
 *         type="http",
 *         scheme="bearer",
 *         bearerFormat="Sanctum"
 *     ),
 *     @OA\Schema(
 *         schema="Room",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="Моя комната"),
 *         @OA\Property(property="master_prompt", type="string", nullable=true),
 *         @OA\Property(property="status", type="string", example="waiting"),
 *         @OA\Property(property="max_players", type="integer", example=4),
 *         @OA\Property(property="created_by", type="integer", example=1),
 *         @OA\Property(property="created_at", type="string", format="date-time"),
 *         @OA\Property(property="updated_at", type="string", format="date-time")
 *     ),
 *     @OA\Schema(
 *         schema="User",
 *         type="object",
 *         @OA\Property(property="id", type="integer", example=1),
 *         @OA\Property(property="name", type="string", example="John Doe"),
 *         @OA\Property(property="email", type="string", example="john@example.com"),
 *         @OA\Property(property="is_admin", type="boolean", example=false)
 *     )
 * )
 */
class BaseController extends Controller
{
    // Базовый контроллер для документации
}
