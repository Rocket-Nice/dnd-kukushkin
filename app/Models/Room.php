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
     * Позволяет не долбить БД по разу на каждое сообщение в истории, и даёт
     * обратный поиск user_id по имени персонажа (нужен для боевой механики —
     * атака NPC резолвится против AC персонажа, найденного по имени из ответа ИИ).
     */
    private array $characterNamesMap = [];
    private array $userIdsByCharacterName = [];
    private bool $characterNamesLoaded = false;

    private function loadCharacterNames(): void
    {
        if ($this->characterNamesLoaded) {
            return;
        }

        $rows = DB::table('room_user')
            ->where('room_id', $this->id)
            ->get(['user_id', 'character_name']);

        foreach ($rows as $row) {
            $this->characterNamesMap[$row->user_id] = $row->character_name ?: null;

            if ($row->character_name) {
                $this->userIdsByCharacterName[$row->character_name] = $row->user_id;
            }
        }

        $this->characterNamesLoaded = true;
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

        $this->loadCharacterNames();

        return $this->characterNamesMap[$userId] ?? null;
    }

    /**
     * Обратный поиск: user_id по точному имени персонажа. Используется
     * боевой механикой, чтобы резолвить атаку NPC на персонажа по имени,
     * которое дал ИИ, против реального AC из БД.
     */
    public function userIdForCharacterName(string $characterName): ?int
    {
        $this->loadCharacterNames();

        return $this->userIdsByCharacterName[$characterName] ?? null;
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

}
