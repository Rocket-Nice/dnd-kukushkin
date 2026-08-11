<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    /**
     * Кэш имён персонажей в рамках текущего запроса (не путать с Cache-фасадом).
     * Позволяет не долбить БД по разу на каждое сообщение в истории.
     */
    private array $characterNamesMap = [];
    private bool $characterNamesLoaded = false;

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
     * Единая точка получения имени персонажа по user_id в рамках комнаты.
     * Заменяет разбросанные по контроллерам/событиям обращения к pivot
     * (важно: у GameMessage/OocMessage user() — обычный belongsTo,
     * у него НЕТ pivot, поэтому $message->user->pivot всегда был null).
     */
    public function characterNameForUser(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        if (! $this->characterNamesLoaded) {
            $rows = DB::table('room_user')
                ->where('room_id', $this->id)
                ->get(['user_id', 'character_name']);

            foreach ($rows as $row) {
                $this->characterNamesMap[$row->user_id] = $row->character_name ?: null;
            }

            $this->characterNamesLoaded = true;
        }

        return $this->characterNamesMap[$userId] ?? null;
    }
}