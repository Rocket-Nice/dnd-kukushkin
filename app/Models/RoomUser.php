<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;

class RoomUser extends Pivot
{
    protected $table = 'room_user';

    protected $casts = [
        'abilities' => 'array',
        'is_ready' => 'boolean',
        'joined_at' => 'datetime',
    ];

    public $timestamps = true;

    public function modifier($stat)
    {
        return floor(($this->$stat - 10) / 2);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function room()
    {
        return $this->belongsTo(Room::class);
    }
}