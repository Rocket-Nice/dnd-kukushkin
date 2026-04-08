<?php

namespace App\Filament\Resources;

use App\Filament\Resources\RoomResource\Pages;
use App\Models\Room;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Columns\TextColumn;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

class RoomResource extends Resource
{
    protected static ?string $model = Room::class;
    protected static ?string $navigationIcon = 'heroicon-o-rectangle-stack';

    public static function form(Forms\Form $form): Forms\Form
    {
        return $form
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->label('Название комнаты'),

                Select::make('status')
                    ->options([
                        'waiting' => 'Ожидание',
                        'playing' => 'В игре',
                        'finished' => 'Завершена',
                    ])
                    ->required()
                    ->label('Статус'),

                Select::make('max_players')
                    ->options([
                        2 => '2 игрока',
                        3 => '3 игрока',
                        4 => '4 игрока',
                    ])
                    ->required()
                    ->label('Максимум игроков'),

                Textarea::make('master_prompt')
                    ->label('Промт мастера')
                    ->rows(5)
                    ->nullable(),
            ]);
    }

    public static function table(Tables\Table $table): Tables\Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('name')->label('Название')->searchable(),
                TextColumn::make('status')->label('Статус')->badge()
                    ->color(fn(string $state): string => match ($state) {
                        'waiting' => 'warning',
                        'playing' => 'success',
                        'finished' => 'danger',
                    }),
                TextColumn::make('users_count')->label('Игроков')->counts('users'),
                TextColumn::make('created_at')->label('Создана')->dateTime(),
            ])
            ->filters([])
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\DeleteBulkAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListRooms::route('/'),
            'create' => Pages\CreateRoom::route('/create'),
            'edit' => Pages\EditRoom::route('/{record}/edit'),
        ];
    }
}
