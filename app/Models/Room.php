<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

class Room extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 
        'master_prompt', 
        'status', 
        'max_players', 
        'created_by'
    ];

    protected $casts = [
        'status' => 'string',
    ];

    protected static function booted()
    {
        // При обновлении комнаты
        static::updated(function ($room) {
            Cache::forget("room_{$room->id}_data");
        });

        // При удалении комнаты
        static::deleted(function ($room) {
            // Очищаем все кэши, связанные с комнатой
            Cache::forget("room_{$room->id}_data");
            Cache::forget("room_{$room->id}_users");
            Cache::forget("room_{$room->id}_messages");
            Cache::forget("room_{$room->id}_game_state");
            
            // Очищаем кэш для всех пользователей комнаты
            $users = $room->users()->get();
            foreach ($users as $user) {
                Cache::forget("user_{$user->id}_rooms");
            }
        });
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function users()
    {
        return $this->belongsToMany(User::class)
            ->using(RoomUser::class)
            ->withPivot([
                'character_name', 
                'character_description', 
                'character_class',
                'strength', 
                'dexterity', 
                'constitution', 
                'intelligence', 
                'wisdom', 
                'charisma',
                'max_hp', 
                'current_hp', 
                'armor_class', 
                'abilities', 
                'is_ready', 
                'joined_at'
            ])
            ->withTimestamps();
    }

    public function gameMessages()
    {
        return $this->hasMany(GameMessage::class);
    }

    public function oocMessages()
    {
        return $this->hasMany(OocMessage::class);
    }

    public function isFull()
    {
        return $this->users()->count() >= $this->max_players;
    }

    public function isUserInRoom($userId)
    {
        return $this->users()->where('user_id', $userId)->exists();
    }

    /**
     * Получить кэшированных пользователей комнаты
     */
    public function getCachedUsers()
    {
        return Cache::remember("room_{$this->id}_users", 3600, function () {
            return $this->users()->get();
        });
    }

    /**
     * Получить кэшированное состояние игры
     */
    public function getCachedGameState()
    {
        return Cache::remember("room_{$this->id}_game_state", 300, function () {
            return [
                'status' => $this->status,
                'users_count' => $this->users()->count(),
                'ready_count' => $this->users()->wherePivot('is_ready', true)->count(),
                'last_message' => $this->gameMessages()->latest()->first(),
            ];
        });
    }

    /**
     * Получить кэшированных пользователей комнаты (для использования как свойство)
     */
    public function getCachedUsersAttribute()
    {
        return Cache::remember('room_' . $this->id . '_users', 300, function () {
            return $this->users()->get();
        });
    }

    /**
     * Получить кэшированные игровые сообщения (для использования как свойство)
     */
    public function getCachedGameMessagesAttribute()
    {
        return Cache::remember('room_' . $this->id . '_game_messages', 60, function () {
            return $this->gameMessages()->latest()->limit(50)->get();
        });
    }

    /**
     * Получить кэшированные OOC сообщения (для использования как свойство)
     */
    public function getCachedOocMessagesAttribute()
    {
        return Cache::remember('room_' . $this->id . '_ooc_messages', 60, function () {
            return $this->oocMessages()->latest()->limit(50)->get();
        });
    }

    /**
     * Получить количество пользователей (кэшированное)
     */
    public function getCachedUsersCountAttribute()
    {
        return Cache::remember('room_' . $this->id . '_users_count', 60, function () {
            return $this->users()->count();
        });
    }

    /**
     * Получить количество готовых игроков (кэшированное)
     */
    public function getCachedReadyCountAttribute()
    {
        return Cache::remember('room_' . $this->id . '_ready_count', 60, function () {
            return $this->users()->wherePivot('is_ready', true)->count();
        });
    }
}