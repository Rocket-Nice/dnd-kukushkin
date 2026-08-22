# Техническая документация проекта: D&D AI Game Platform

Laravel-приложение для совместной текстовой D&D-игры с ИИ-мастером (DeepSeek), real-time чатом через WebSockets и серверной боевой механикой (броски кубиков, урон, HP/AC).

Документ рассчитан на разработчика, который впервые открывает проект: описывает архитектуру целиком, от роута до строки в БД, с кодом и объяснением связей между слоями.

---

## Оглавление

1. [Обзор и технологический стек](#1-обзор-и-технологический-стек)
2. [Структура директорий](#2-структура-директорий)
3. [Модель данных](#3-модель-данных)
4. [Архитектурный слой (SOLID): как проходит запрос](#4-архитектурный-слой-solid-как-проходит-запрос)
5. [Eloquent-модели](#5-eloquent-модели)
6. [Policy: авторизация](#6-policy-авторизация)
7. [FormRequest: валидация](#7-formrequest-валидация)
8. [Controllers: тонкий слой](#8-controllers-тонкий-слой)
9. [Services: вся бизнес-логика](#9-services-вся-бизнес-логика)
10. [Events и Real-time (WebSockets)](#10-events-и-real-time-websockets)
11. [Боевая механика и протокол AI-директив](#11-боевая-механика-и-протокол-ai-директив)
12. [Frontend: room.js](#12-frontend-roomjs)
13. [Blade-шаблоны](#13-blade-шаблоны)
14. [Маршруты](#14-маршруты)
15. [Конфигурация](#15-конфигурация)
16. [Сценарии целиком (request lifecycle)](#16-сценарии-целиком-request-lifecycle)
17. [Известные ограничения и технический долг](#17-известные-ограничения-и-технический-долг)

---

## 1. Обзор и технологический стек

**Backend:** PHP 8.2+, Laravel 10, MySQL, Redis (кэш/сессии/очереди), Laravel Sanctum (для API-токенов, используется частично)

**Real-time:** Laravel Broadcasting + Pusher (протокол), Laravel Echo + pusher-js на фронте, приватные каналы с авторизацией

**AI:** DeepSeek Chat API (OpenAI-совместимый `/v1/chat/completions`), вызывается через `Illuminate\Support\Facades\Http`

**Frontend:** Blade (server-rendered), ванильный JS (класс `DnDRoom` в `room.js`), Tailwind CSS, Alpine.js (базово, не используется в игровой логике), Vite

**Архитектурный паттерн:** классический Laravel-слоями —

```
Route → Middleware → FormRequest (валидация + authorize()) → Controller (тонкий) → Service (бизнес-логика) → Model/Event → Response
```

Плюс `Policy` как единая точка правил доступа, переиспользуемая и в `FormRequest::authorize()`, и напрямую в контроллерах через `$this->authorize()`.

---

## 2. Структура директорий

```
app/
  Console/Commands/
    CleanOldRooms.php          — cron-команда очистки старых комнат (НЕ отрефакторена, см. раздел 17)
  Events/
    GameMessageSent.php
    OocMessageSent.php
    RoomStatusUpdated.php      — файл называется RoomStatusUpdate.php (несовпадение имени, см. раздел 17)
    CharacterStatsUpdated.php
  Http/
    Controllers/
      RoomController.php
      GameMessageController.php
      OocMessageController.php
      Api/                     — отдельная, НЕ отрефакторенная ветка API-контроллеров
        AuthController.php
        RoomController.php
        BaseController.php     — только Swagger-аннотации, логики нет
    Requests/
      Room/
        StoreRoomRequest.php
        SaveCharacterRequest.php
      Chat/
        StoreGameMessageRequest.php
        StoreOocMessageRequest.php
  Models/
    Room.php
    User.php
    GameMessage.php
    OocMessage.php
    RoomUser.php                — pivot-модель room_user
  Policies/
    RoomPolicy.php
  Providers/
    AuthServiceProvider.php     — регистрирует RoomPolicy
    BroadcastServiceProvider.php
    AppServiceProvider.php
  Services/
    RoomService.php             — жизненный цикл комнаты
    GameChatService.php         — отправка игровых сообщений, /roll
    OocChatService.php          — отправка OOC-сообщений
    GameMasterService.php       — оркестрация диалога с AI, парсинг директив
    CombatService.php           — применение изменений HP/AC/max_hp к БД
    AttackService.php           — резолв боевых бросков (d20 vs AC, урон)
    DiceService.php             — примитив бросков кубиков
    DeepSeekService.php         — HTTP-клиент к DeepSeek API

resources/
  js/
    app.js                      — точка входа Vite: bootstrap + room + Alpine
    bootstrap.js                 — только axios (Echo здесь НЕ создаётся)
    room.js                      — вся клиентская логика игровой комнаты (класс DnDRoom)
  views/
    layouts/
      app.blade.php
      navigation.blade.php
      guest.blade.php
    rooms/
      index.blade.php
      create.blade.php
      show.blade.php             — главный экран комнаты (чат, боёвка, HP-бары)
      confirm-destroy.blade.php
    home.blade.php
    dashboard.blade.php

routes/
  web.php
  api.php
  channels.php                   — авторизация приватных WebSocket-каналов

config/
  database.php                   — конфиг Redis (отдельные БД под cache/session/queue)
  services.php                   — ключ DeepSeek
```

---

## 3. Модель данных

### Таблицы

**rooms**
| поле | тип | описание |
|---|---|---|
| id | bigint PK | |
| name | string | |
| master_prompt | text nullable | кастомный промпт мастера от создателя комнаты |
| status | enum('waiting','playing','finished') | |
| max_players | tinyint unsigned, default 4 | |
| created_by | FK → users.id | создатель/мастер комнаты |
| timestamps | | |

**game_messages**
| поле | тип | описание |
|---|---|---|
| id | bigint PK | |
| room_id | FK → rooms | |
| user_id | FK → users, nullable | null для сообщений роли assistant/system |
| role | enum('user','assistant','system') | |
| content | text | |
| timestamps | | |

**ooc_messages** — то же самое, но без `role` (всегда обычное сообщение игрока в внеигровом чате).

**room_user** (pivot, композитный PK `[room_id, user_id]`)
| поле | тип | описание |
|---|---|---|
| room_id, user_id | FK, составной PK | |
| character_name | string nullable | |
| character_description | text nullable | |
| character_class | string nullable | fighter/wizard/rogue/cleric/ranger/paladin/bard/barbarian |
| strength/dexterity/constitution/intelligence/wisdom/charisma | tinyint unsigned, default 10 | характеристики D&D |
| max_hp, current_hp | int, default 30 | |
| armor_class | tinyint unsigned, default 10 | |
| abilities | json nullable | зарезервировано, сейчас не используется активно |
| is_ready | boolean, default false | true после создания персонажа |
| joined_at | timestamp | |
| timestamps | | |

### Связи (Eloquent)

```
User
 └─ belongsToMany(Room) через room_user   [$user->rooms]

Room
 ├─ belongsTo(User, 'created_by')          [$room->creator]
 ├─ belongsToMany(User) через room_user    [$room->users] — с withPivot(...) всех игровых полей
 ├─ hasMany(GameMessage)                   [$room->gameMessages]
 └─ hasMany(OocMessage)                    [$room->oocMessages]

GameMessage / OocMessage
 ├─ belongsTo(Room)
 └─ belongsTo(User)   ⚠️ ВАЖНО: это обычный belongsTo, у него НЕТ pivot-данных.
                          $message->user->pivot всегда null — это была причина
                          известного бага "имя персонажа всегда показывает System".
                          Для получения имени персонажа используйте
                          Room::characterNameForUser($userId), а не user()->pivot.

RoomUser (pivot-модель room_user, extends Pivot)
 ├─ belongsTo(User)
 ├─ belongsTo(Room)
 └─ modifier($stat): floor(($this->$stat - 10) / 2) — формула модификатора D&D
```

---

## 4. Архитектурный слой (SOLID): как проходит запрос

До рефакторинга вся логика (валидация, авторизация, бизнес-правила, работа с кэшем, broadcasting) лежала прямо в контроллерах. Сейчас — строгое разделение ответственности:

```
┌─────────────┐     ┌────────────┐     ┌──────────────┐     ┌─────────────┐
│   Route      │ →   │ FormRequest │ →   │  Controller   │ →   │   Service    │
│ (web.php)    │     │ authorize() │     │  (тонкий)     │     │ (бизнес-     │
│              │     │ rules()     │     │               │     │  логика)     │
└─────────────┘     └─────┬──────┘     └──────┬───────┘     └──────┬──────┘
                           │                     │                     │
                           ▼                     ▼                     ▼
                     ┌───────────┐        $this->authorize()    Model / Event /
                     │ RoomPolicy │ ←──────────────┘             другой Service
                     └───────────┘
```

**Кто за что отвечает:**

| Слой | Отвечает за | Не отвечает за |
|---|---|---|
| `FormRequest` | Валидация полей (`rules()`), базовая авторизация конкретного действия (`authorize()`) | Бизнес-логику, работу с БД сверх валидации |
| `Policy` | Единственный источник правды "кто что может" (владелец комнаты? участник? игра идёт?) | Валидацию полей |
| `Controller` | Достать данные из запроса → вызвать Policy/Service → сформировать HTTP-ответ | Бизнес-правила, транзакции, broadcasting |
| `Service` | Вся бизнес-логика: транзакции, вычисления, обращения к нескольким моделям, вызов `broadcast()` | HTTP-специфику (коды ответов, redirect) |
| `Event` | Описание, что произошло, и что транслировать по WebSocket | Логику принятия решений |

Пример: маршрут `POST /rooms/{room}/game-messages` →

```
routes/web.php
  → GameMessageController::store(StoreGameMessageRequest $request, Room $room)
      → StoreGameMessageRequest::authorize() вызывает $this->user()->can('sendMessage', $room)
          → это резолвится в RoomPolicy::sendMessage()
      → контроллер вызывает $this->chat->sendMessage($room, $user, $message)
          → GameChatService делает всю работу: создаёт GameMessage, вызывает
            GameMasterService (который вызывает DeepSeekService, CombatService,
            AttackService), рассылает события
      → контроллер формирует JSON-ответ из того, что вернул сервис
```

---

## 5. Eloquent-модели

### `app/Models/Room.php`

Центральная модель. Помимо стандартных Eloquent-связей несёт два метода, которые заменяют разбросанную по всему проекту логику резолва персонажей:

```php
class Room extends Model
{
    protected $fillable = ['name', 'master_prompt', 'status', 'max_players', 'created_by'];
    protected $casts = ['status' => 'string'];

    // Кэш имён персонажей В РАМКАХ ТЕКУЩЕГО HTTP-ЗАПРОСА (не Cache-фасад!).
    // Строится один раз одним запросом к room_user, дальше — из памяти.
    private array $characterNamesMap = [];
    private array $userIdsByCharacterName = [];
    private bool $characterNamesLoaded = false;

    private function loadCharacterNames(): void
    {
        if ($this->characterNamesLoaded) return;

        $rows = DB::table('room_user')->where('room_id', $this->id)
            ->get(['user_id', 'character_name']);

        foreach ($rows as $row) {
            $this->characterNamesMap[$row->user_id] = $row->character_name ?: null;
            if ($row->character_name) {
                $this->userIdsByCharacterName[$row->character_name] = $row->user_id;
            }
        }
        $this->characterNamesLoaded = true;
    }

    // user_id → имя персонажа. Используется везде, где раньше ошибочно
    // писали $message->user->pivot->character_name (см. раздел 3).
    public function characterNameForUser(?int $userId): ?string
    {
        if ($userId === null) return null;
        $this->loadCharacterNames();
        return $this->characterNamesMap[$userId] ?? null;
    }

    // Обратный поиск: имя персонажа (данное AI в ответе) → user_id.
    // Нужен боевой механике — AI называет цель атаки по имени, а нам
    // нужен реальный user_id, чтобы найти AC в БД.
    public function userIdForCharacterName(string $characterName): ?int
    {
        $this->loadCharacterNames();
        return $this->userIdsByCharacterName[$characterName] ?? null;
    }

    public function isFull(): bool
    {
        return $this->users()->count() >= $this->max_players;
    }

    public function isUserInRoom($userId): bool
    {
        return $this->users()->where('user_id', $userId)->exists();
    }
}
```

**Почему `characterNamesMap` — свойство объекта, а не `Cache::remember`:** предыдущая версия проекта кэшировала это через Redis с TTL, и именно рассинхрон этого кэша был причиной нескольких продакшн-багов (модалка создания персонажа всплывала повторно у игроков, которые уже создали персонажа; имя в чате не обновлялось после смены). In-memory кэш на время запроса даёт тот же выигрыш (не долбим БД на каждое из 20 сообщений истории), но обнуляется каждый новый запрос — не может протухнуть неправильно.

### `app/Models/RoomUser.php`

```php
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
}
```

### `GameMessage.php` / `OocMessage.php` / `User.php`

Стандартные модели без дополнительной логики — `$fillable`, `belongsTo`/`belongsToMany`.

---

## 6. Policy: авторизация

### `app/Policies/RoomPolicy.php`

Единственное место в проекте, где сформулированы правила доступа. Используется и из `FormRequest::authorize()`, и напрямую из контроллеров через `$this->authorize()`.

```php
class RoomPolicy
{
    public function view(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id);
    }

    public function join(User $user, Room $room): bool
    {
        return !$room->isFull() && !$room->isUserInRoom($user->id);
    }

    public function manageCharacter(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status === 'waiting';
    }

    public function leave(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status !== 'playing';
    }

    public function sendMessage(User $user, Room $room): bool
    {
        return $room->isUserInRoom($user->id) && $room->status === 'playing';
    }

    public function start(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }

    public function destroy(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }

    public function kickAll(User $user, Room $room): bool
    {
        return $room->created_by === $user->id;
    }
}
```

Зарегистрирована в `AuthServiceProvider`:

```php
protected $policies = [
    Room::class => RoomPolicy::class,
];
```

До этого рефакторинга проверка `if ($room->created_by !== Auth::id())` была скопипащена в `RoomController` пять раз с разным поведением при провале (то `redirect`, то JSON 403). Сейчас это одна функция `start()`/`destroy()`/`kickAll()`, поведение при провале решает вызывающий код (контроллер или FormRequest), а не Policy.

---

## 7. FormRequest: валидация

Каждый `FormRequest` совмещает `rules()` (валидация полей) и `authorize()` (делегирует в Policy). Route-model binding (`{room}`) резолвится Laravel ДО вызова `authorize()`, поэтому `$this->route('room')` внутри уже настоящий объект `Room`.

### `app/Http/Requests/Room/StoreRoomRequest.php`

```php
class StoreRoomRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // создать комнату может любой аутентифицированный пользователь
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'master_prompt' => 'nullable|string|max:5000',
            'max_players' => 'integer|min:2|max:4',
        ];
    }
}
```

### `app/Http/Requests/Room/SaveCharacterRequest.php`

```php
class SaveCharacterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageCharacter', $this->route('room'));
    }

    public function rules(): array
    {
        return [
            'character_name' => 'required|string|max:255',
            'character_description' => 'nullable|string|max:2000',
            'character_class' => 'required|string|in:fighter,wizard,rogue,cleric,ranger,paladin,bard,barbarian',
            'strength' => 'required|integer|min:3|max:20',
            'dexterity' => 'required|integer|min:3|max:20',
            'constitution' => 'required|integer|min:3|max:20',
            'intelligence' => 'required|integer|min:3|max:20',
            'wisdom' => 'required|integer|min:3|max:20',
            'charisma' => 'required|integer|min:3|max:20',
        ];
    }
}
```

### `app/Http/Requests/Chat/StoreGameMessageRequest.php`

```php
class StoreGameMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sendMessage', $this->route('room'));
    }

    public function rules(): array
    {
        return ['message' => 'required|string|max:2000'];
    }
}
```

### `app/Http/Requests/Chat/StoreOocMessageRequest.php`

```php
class StoreOocMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Для OOC-чата достаточно состоять в комнате — игра необязательно должна идти
        return $this->user()->can('view', $this->route('room'));
    }

    public function rules(): array
    {
        return ['message' => 'required|string|max:1000'];
    }
}
```

---

## 8. Controllers: тонкий слой

Контроллеры не содержат бизнес-логики. Их задача в три строки: авторизовать (через Policy или через типизацию FormRequest) → вызвать сервис → вернуть ответ.

### `RoomController` (ключевые методы)

```php
class RoomController extends Controller
{
    public function __construct(private RoomService $rooms)
    {
        $this->middleware('auth');
    }

    public function store(StoreRoomRequest $request)
    {
        $room = $this->rooms->create($request->validated(), $request->user()->id);
        return redirect()->route('rooms.show', ['room' => $room->id])
            ->with('success', 'Комната создана!');
    }

    public function show(Room $room)
    {
        $this->authorize('view', $room);
        $character = $room->users()->where('user_id', Auth::id())->first()?->pivot;
        return view('rooms.show', compact('room', 'character'));
    }

    public function start(Room $room)
    {
        $this->authorize('start', $room);
        try {
            $this->rooms->start($room);
        } catch (RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }
        return redirect()->route('rooms.show', $room)->with('success', 'Игра началась!');
    }

    public function saveCharacter(SaveCharacterRequest $request, Room $room)
    {
        $this->rooms->saveCharacter($room, Auth::id(), $request->validated());
        return redirect()->route('rooms.show', $room)
            ->with('success', 'Персонаж создан!')->with('character_created', true);
    }

    // join, leave, destroy, kickAll, status, index, create — того же вида
}
```

Обратите внимание: `join()` и `leave()` НЕ передают проверку через Policy в виде `$this->authorize()`, а используют явные условия с собственными сообщениями об ошибке (`'Комната заполнена'`, `'Вы уже в комнате'`) — это осознанное решение: пользователю нужно понятное сообщение, а не общий 403 от Policy.

### `GameMessageController::store` — полностью

```php
public function store(StoreGameMessageRequest $request, Room $room)
{
    try {
        $result = $this->chat->sendMessage($room, $request->user(), $request->validated()['message']);

        return response()->json([
            'success' => true,
            'user_message' => $result['user_message'] ? $this->chat->formatMessage($result['user_message'], $room) : null,
            'system_message' => $result['system_message'] ? $this->chat->formatMessage($result['system_message'], $room) : null,
            'combat_messages' => array_map(
                fn ($m) => $this->chat->formatMessage($m, $room),
                $result['combat_messages'] ?? []
            ),
            'roll' => $result['roll'],
            'ai_message' => $this->chat->formatMessage($result['ai_message'], $room),
            'stat_changes' => $result['stat_changes'],
        ]);

    } catch (RuntimeException $e) {
        // Ожидаемые бизнес-ошибки ("сначала создайте персонажа")
        return response()->json(['error' => $e->getMessage()], 400);
    } catch (\Throwable $e) {
        Log::error('GameMessageController@store error: ' . $e->getMessage());
        return response()->json(['error' => 'Мастер временно недоступен, попробуйте ещё раз'], 502);
    }
}
```

Контроллер не знает НИЧЕГО о том, что происходит внутри `sendMessage` (создаётся ли системное сообщение, резолвится ли атака, вызывается ли AI) — это осознанная граница ответственности.

---

## 9. Services: вся бизнес-логика

Это ядро проекта. Ниже — каждый сервис с полным кодом и объяснением связей.

### 9.1 `RoomService` — жизненный цикл комнаты

Отвечает за все мутации над `Room`/`room_user`: создание, вход/выход, старт игры, сохранение персонажа, кик, удаление.

```php
class RoomService
{
    public function __construct(private GameMasterService $gameMaster) {}

    public function create(array $data, int $userId): Room
    {
        $room = Room::create([...$data, 'created_by' => $userId]);
        $room->users()->attach($userId, ['joined_at' => now()]);
        return $room;
    }

    public function join(Room $room, int $userId): void
    {
        $room->users()->syncWithoutDetaching([$userId => ['joined_at' => now()]]);
        broadcast(new RoomStatusUpdated($room));
    }

    public function leave(Room $room, int $userId): void
    {
        DB::transaction(function () use ($room, $userId) {
            GameMessage::where('room_id', $room->id)->where('user_id', $userId)->delete();
            $room->users()->detach($userId);
        });
        broadcast(new RoomStatusUpdated($room));
    }

    public function destroy(Room $room): void
    {
        DB::transaction(function () use ($room) {
            GameMessage::where('room_id', $room->id)->delete();
            OocMessage::where('room_id', $room->id)->delete();
            $room->users()->detach();
            $room->delete();
        });
    }

    public function kickAll(Room $room): void
    {
        DB::transaction(function () use ($room) {
            $room->users()->where('user_id', '!=', $room->created_by)->detach();
            $room->update(['status' => 'waiting']);
            GameMessage::where('room_id', $room->id)->delete();
        });
        broadcast(new RoomStatusUpdated($room));
    }

    public function saveCharacter(Room $room, int $userId, array $data): void
    {
        $modCon = floor(($data['constitution'] - 10) / 2);
        $maxHp = 30 + $modCon * 2;
        $modDex = floor(($data['dexterity'] - 10) / 2);
        $armorClass = 10 + $modDex;

        $room->users()->updateExistingPivot($userId, [
            ...$data,
            'max_hp' => $maxHp,
            'current_hp' => $maxHp,
            'armor_class' => $armorClass,
            'is_ready' => true,
        ]);

        broadcast(new RoomStatusUpdated($room));
    }

    public function start(Room $room): void
    {
        $readyCount = $room->users()->wherePivot('is_ready', true)->count();
        if ($readyCount < 2) {
            throw new RuntimeException('Нужно минимум 2 готовых игрока');
        }

        $room->update(['status' => 'playing']);
        broadcast(new RoomStatusUpdated($room));

        $intro = $this->gameMaster->generateIntro($room);
        broadcast(new GameMessageSent($intro, $room));
    }
}
```

**Формулы D&D:** `max_hp = 30 + modifier(CON) * 2`, `armor_class = 10 + modifier(DEX)`, где `modifier(x) = floor((x - 10) / 2)` — упрощённая версия правил 5-й редакции, зашитая как константа (не настраивается).

**Зависимость от `GameMasterService`:** `start()` не просто меняет статус — он же генерирует и рассылает вступительное сообщение мастера. Раньше это вступление создавалось, но никогда не транслировалось по WebSocket — баг, из-за которого игроки не видели начало игры без ручного обновления страницы.

### 9.2 `GameChatService` — отправка игровых сообщений

Самый сложный по количеству сценариев сервис. Разбирает три ветки: обычное сообщение, `/roll` как обычная d20-проверка, `/roll` как резолв заранее заготовленной атаки.

```php
class GameChatService
{
    public function __construct(
        private GameMasterService $gameMaster,
        private DiceService $dice,
        private AttackService $attack,
    ) {}

    public function sendMessage(Room $room, User $user, string $rawMessage): array
    {
        $characterPivot = $room->users()->where('user_id', $user->id)->first()?->pivot;
        if (!$characterPivot || !$characterPivot->character_name) {
            throw new RuntimeException('Сначала создайте персонажа');
        }

        $message = trim($rawMessage);
        $isRoll = str_starts_with($message, '/roll');

        $userMessageModel = null;
        $systemMessageModel = null;
        $rollData = null;

        if (!$isRoll) {
            $userMessageModel = GameMessage::create([
                'room_id' => $room->id, 'user_id' => $user->id,
                'role' => 'user', 'content' => $message,
            ]);
            broadcast(new GameMessageSent($userMessageModel, $room))->toOthers();
        } else {
            $rollData = $this->resolveRoll($room, $user, $characterPivot, $message);
            $systemMessageModel = GameMessage::create([
                'room_id' => $room->id, 'role' => 'system', 'content' => $rollData['message'],
            ]);
            broadcast(new GameMessageSent($systemMessageModel, $room))->toOthers();
        }

        // Всегда вызываем AI — и после обычного сообщения, и после броска
        // (чтобы мастер отреагировал на результат)
        $result = $this->gameMaster->processMessage($room, $user, $message, $rollData['message'] ?? null);

        $aiMessageModel = $result['message'];
        $statChanges = $result['stat_changes'];
        $combatMessages = $result['combat_messages'] ?? [];

        broadcast(new GameMessageSent($aiMessageModel, $room))->toOthers();

        foreach ($combatMessages as $combatMessage) {
            broadcast(new GameMessageSent($combatMessage, $room))->toOthers();
        }

        if (!empty($statChanges)) {
            broadcast(new CharacterStatsUpdated($room, $statChanges));
            broadcast(new RoomStatusUpdated($room));
        }

        return [
            'user_message' => $userMessageModel,
            'system_message' => $systemMessageModel,
            'combat_messages' => $combatMessages,
            'roll' => $rollData,
            'ai_message' => $aiMessageModel,
            'stat_changes' => $statChanges,
        ];
    }

    private function resolveRoll(Room $room, User $user, object $characterPivot, string $message): array
    {
        // Ключевая развилка: если для этого игрока в этой комнате AI ранее
        // (в предыдущем своём ответе) выставил "отложенную атаку" через
        // директиву PENDING_ATTACK — резолвим её как боевой бросок против NPC.
        $pendingAttack = $this->attack->consumePendingAttack($room, $user->id);

        if ($pendingAttack && isset($pendingAttack['target_ac'])) {
            $attackBonus = $this->attack->characterAttackBonus($characterPivot);
            $npcName = (string) ($pendingAttack['npc_name'] ?? 'противник');

            $result = $this->attack->resolveAttack(
                $characterPivot->character_name, $attackBonus,
                (int) $pendingAttack['target_ac'],
                (string) ($pendingAttack['damage_dice'] ?? '1d6'),
                (int) ($pendingAttack['damage_bonus'] ?? 0)
            );

            return [
                'roll' => $result['attack_roll'],
                'difficulty' => $result['target_ac'],
                'success' => $result['hit'],
                'level' => $result['fumble'] ? '💀 Критический промах!'
                    : ($result['critical'] ? '🎯 Критический удар!'
                    : ($result['hit'] ? '✅ Попадание' : '❌ Промах')),
                'message' => $this->attack->formatMessage($result, $npcName),
            ];
        }

        // Иначе — обычная d20-проверка навыка (сложность игрок мог указать
        // сам текстом: "/roll 15")
        preg_match('/\/roll\s*(\d+)?/', $message, $matches);
        $difficulty = isset($matches[1]) ? (int) $matches[1] : null;
        return $this->dice->roll($difficulty);
    }

    public function formatMessage(GameMessage $message, Room $room): array
    {
        $userName = match (true) {
            $message->role === 'assistant' => 'Мастер',
            $message->role === 'system' => 'System',
            (bool) $message->user_id => $room->characterNameForUser($message->user_id)
                ?? $message->user?->name ?? 'Игрок',
            default => 'System',
        };

        return [
            'id' => $message->id, 'role' => $message->role, 'content' => $message->content,
            'user_name' => $userName, 'user_id' => $message->user_id,
            'created_at' => $message->created_at->timestamp,
        ];
    }
}
```

**`formatMessage()`** — единая точка форматирования сообщения в JSON и для HTTP-ответа, и (косвенно, через ту же логику в событиях) для WebSocket-рассылки. Раньше эта логика (определение отображаемого имени по роли) была продублирована в трёх местах: контроллере, событии и Blade-шаблоне — с разным результатом в каждом.

### 9.3 `OocChatService` — простой сервис-аналог для внеигрового чата

```php
class OocChatService
{
    public function sendMessage(Room $room, int $userId, string $rawMessage): OocMessage
    {
        $message = OocMessage::create([
            'room_id' => $room->id, 'user_id' => $userId, 'content' => trim($rawMessage),
        ]);
        $message->load('user');
        broadcast(new OocMessageSent($message, $room))->toOthers();
        return $message;
    }

    public function formatMessage(OocMessage $msg): array
    {
        return [
            'id' => $msg->id, 'content' => $msg->content,
            'user_name' => $msg->user->name, 'user_id' => $msg->user_id,
            'created_at' => $msg->created_at->timestamp,
        ];
    }
}
```

### 9.4 `GameMasterService` — оркестрация диалога с AI

Самый насыщенный сервис. Строит промпт, шлёт запрос в DeepSeek, парсит служебные директивы из ответа, вызывает `CombatService` и `AttackService` для применения последствий.

```php
class GameMasterService
{
    public function __construct(
        protected DeepSeekService $deepSeek,
        protected CombatService $combat,
        protected AttackService $attack,
    ) {}

    public function generateIntro(Room $room): GameMessage
    {
        $characters = $room->users()->wherePivot('is_ready', true)->get()
            ->map(fn ($u) => "{$u->pivot->character_name} - {$u->pivot->character_description} (Класс: {$u->pivot->character_class})")
            ->join("\n");

        $response = $this->deepSeek->chat([[
            'role' => 'system',
            'content' => "Ты — опытный Мастер Подземелий... Создай захватывающее начало приключения для этих персонажей:\n\n$characters\n\n...",
        ]]);

        return GameMessage::create(['room_id' => $room->id, 'role' => 'assistant', 'content' => $response]);
    }

    public function processMessage(Room $room, User $user, string $userMessage, ?string $rollResult = null): array
    {
        // 1. Собрать контекст: текущий персонаж, все живые персонажи (HP/AC), история (20 последних сообщений)
        $userRoomData = $room->users()->where('user_id', $user->id)->first();
        $character = $userRoomData->pivot;

        $allCharacters = $room->users()->wherePivot('is_ready', true)->get()->map(fn ($u) => [
            'character_name' => $u->pivot->character_name,
            'character_class' => $u->pivot->character_class,
            'hp' => $u->pivot->current_hp . '/' . $u->pivot->max_hp,
            'ac' => $u->pivot->armor_class,
        ]);

        $system = $this->buildSystemPrompt($room, $character, $allCharacters, $user);

        $history = GameMessage::where('room_id', $room->id)->latest()->limit(20)->get()
            ->reverse()->map(function ($msg) use ($room) {
                $role = $msg->role === 'assistant' ? 'assistant' : 'user';
                if ($msg->role === 'user' && $msg->user_id) {
                    $name = $room->characterNameForUser($msg->user_id) ?? 'Игрок ' . $msg->user_id;
                    $content = $name . ': ' . $msg->content;
                } else {
                    $content = $msg->content;
                }
                return ['role' => $role, 'content' => $content];
            })->values()->toArray();

        $messages = [['role' => 'system', 'content' => $system], ...$history];
        if ($rollResult) $messages[] = ['role' => 'system', 'content' => $rollResult];
        if ($userMessage && !str_starts_with($userMessage, '/roll')) {
            $messages[] = ['role' => 'user', 'content' => "{$character->character_name}: $userMessage"];
        }

        // 2. Запрос к DeepSeek
        $rawResponse = $this->deepSeek->chat($messages);

        // 3. Вырезать служебные директивы из хвоста ответа
        $parsed = $this->extractDirectives($rawResponse);

        // 4. Сохранить ОЧИЩЕННЫЙ текст (без директив) как сообщение мастера
        $aiMessage = GameMessage::create([
            'room_id' => $room->id, 'role' => 'assistant',
            'content' => $parsed['content'] !== '' ? $parsed['content'] : $rawResponse,
        ]);

        // 5. Применить STATE (лечение зельем, эффекты без броска)
        $statChanges = $this->combat->applyStateChanges($room, $parsed['directives']['STATE']);
        $combatMessages = [];

        // 6. Резолвить ATTACK (NPC атакует игрока — сервер бросает кубики сам)
        foreach ($parsed['directives']['ATTACK'] as $atk) {
            if (empty($atk['attacker']) || empty($atk['target'])) continue;

            $result = $this->attack->resolveNpcAttackOnCharacter(
                $room, (string) $atk['attacker'], (string) $atk['target'],
                (int) ($atk['attack_bonus'] ?? 3),
                (string) ($atk['damage_dice'] ?? '1d6'),
                (int) ($atk['damage_bonus'] ?? 0)
            );
            if ($result === null) continue; // AI назвал несуществующего персонажа — просто игнорируем

            $combatMessages[] = GameMessage::create([
                'room_id' => $room->id, 'role' => 'system',
                'content' => $this->attack->formatMessage($result, (string) $atk['target']),
            ]);

            if ($result['hit'] && $result['damage'] > 0) {
                $hpChanges = $this->combat->applyStateChanges($room, [
                    ['character' => (string) $atk['target'], 'hp_delta' => -$result['damage']],
                ]);
                $statChanges = [...$statChanges, ...$hpChanges];
            }
        }

        // 7. Сохранить PENDING_ATTACK на будущее (игрок атакует NPC следующим /roll)
        if ($parsed['directives']['PENDING_ATTACK']) {
            $this->attack->storePendingAttack($room, $user->id, $parsed['directives']['PENDING_ATTACK']);
        }

        return ['message' => $aiMessage, 'stat_changes' => $statChanges, 'combat_messages' => $combatMessages];
    }

    private function extractDirectives(string $raw): array
    {
        $content = trim($raw);
        $directives = ['STATE' => [], 'ATTACK' => [], 'PENDING_ATTACK' => null];

        // Директивы стоят строго в конце ответа, могут идти цепочкой —
        // отрезаем с конца по одной, пока строка матчится
        while (preg_match('/\n?\[\[(STATE|ATTACK|PENDING_ATTACK):(\{.*\})\]\]\s*$/s', $content, $m)) {
            $type = $m[1];
            $content = trim(substr($content, 0, -strlen($m[0])));

            try {
                $decoded = json_decode($m[2], true, 8, JSON_THROW_ON_ERROR);
            } catch (\JsonException $e) {
                Log::warning("не удалось распарсить {$type}-блок", ['raw' => $m[2]]);
                continue; // битый JSON — просто нет соответствующих изменений, чат не падает
            }

            if ($type === 'STATE' && is_array($decoded['changes'] ?? null)) {
                $directives['STATE'] = $decoded['changes'];
            } elseif ($type === 'ATTACK' && is_array($decoded['attacks'] ?? null)) {
                $directives['ATTACK'] = $decoded['attacks'];
            } elseif ($type === 'PENDING_ATTACK' && is_array($decoded)) {
                $directives['PENDING_ATTACK'] = $decoded;
            }
        }
        return ['content' => $content, 'directives' => $directives];
    }

    protected function buildSystemPrompt(Room $room, $currentCharacter, $allCharacters, $currentUser): string
    {
        // Полный текст промпта — см. раздел 11. Содержит: список персонажей
        // с HP/AC, правила поведения мастера, и подробную спецификацию
        // трёх типов директив (STATE/ATTACK/PENDING_ATTACK) с примерами.
        // ...
    }
}
```

**Почему директивы парсятся именно с конца строки, а не ищутся по всему тексту:** если искать `[[...]]` где угодно в тексте, есть риск случайно смэтчить что-то в самом повествовании (например, если AI процитирует фигурные скобки). Якорь `\s*$` гарантирует, что мы режем только служебный хвост, который сами же велели добавлять последней строкой.

### 9.5 `CombatService` — применение изменений к БД

Единственное место, которое реально пишет `current_hp`/`max_hp`/`armor_class` в `room_user`. Все дельты жёстко клампятся — это единственная защита от того, что AI "нафантазирует" аномальные числа.

```php
class CombatService
{
    private const HP_MIN_DELTA = -50;
    private const HP_MAX_DELTA = 50;
    private const AC_MIN_DELTA = -10;
    private const AC_MAX_DELTA = 10;
    private const MAX_HP_MIN_DELTA = 0;   // левел-ап только увеличивает максимум
    private const MAX_HP_MAX_DELTA = 50;

    public function applyStateChanges(Room $room, array $changes): array
    {
        if (empty($changes)) return [];

        $nameToUserId = $this->buildCharacterNameMap($room);
        $applied = [];

        foreach ($changes as $change) {
            if (!is_array($change) || empty($change['character'])) continue;

            $userId = $nameToUserId[$change['character']] ?? null;
            if ($userId === null) {
                Log::warning('неизвестный персонаж в STATE-блоке', ['character' => $change['character']]);
                continue;
            }

            $pivot = $room->users()->where('user_id', $userId)->first()?->pivot;
            if (!$pivot) continue;

            $hpDelta = $this->clamp((int) ($change['hp_delta'] ?? 0), self::HP_MIN_DELTA, self::HP_MAX_DELTA);
            $acDelta = $this->clamp((int) ($change['ac_delta'] ?? 0), self::AC_MIN_DELTA, self::AC_MAX_DELTA);
            $maxHpDelta = $this->clamp((int) ($change['max_hp_delta'] ?? 0), self::MAX_HP_MIN_DELTA, self::MAX_HP_MAX_DELTA);

            if ($hpDelta === 0 && $acDelta === 0 && $maxHpDelta === 0) continue;

            $oldCurrentHp = (int) $pivot->current_hp;
            $oldMaxHp = (int) $pivot->max_hp;
            $oldAc = (int) $pivot->armor_class;

            $newMaxHp = max(1, $oldMaxHp + $maxHpDelta);
            // Левел-ап дополнительно "долечивает" на прирост максимума
            $newCurrentHp = max(0, min($newMaxHp, $oldCurrentHp + $hpDelta + $maxHpDelta));
            $newAc = max(0, $oldAc + $acDelta);

            if ($newCurrentHp === $oldCurrentHp && $newMaxHp === $oldMaxHp && $newAc === $oldAc) continue;

            $room->users()->updateExistingPivot($userId, [
                'current_hp' => $newCurrentHp, 'max_hp' => $newMaxHp, 'armor_class' => $newAc,
            ]);

            $applied[] = [
                'user_id' => $userId, 'character_name' => $change['character'],
                'hp_delta' => $newCurrentHp - $oldCurrentHp, // фактическая дельта после клампа
                'current_hp' => $newCurrentHp, 'max_hp' => $newMaxHp, 'armor_class' => $newAc,
                'leveled_up' => $maxHpDelta > 0,
            ];
        }
        return $applied;
    }

    private function buildCharacterNameMap(Room $room): array
    {
        $map = [];
        foreach ($room->users()->wherePivot('is_ready', true)->get() as $user) {
            if ($user->pivot->character_name) $map[$user->pivot->character_name] = $user->id;
        }
        return $map;
    }

    private function clamp(int $value, int $min, int $max): int
    {
        return max($min, min($max, $value));
    }
}
```

Возвращаемый массив `$applied` — это то же самое, что уходит в событие `CharacterStatsUpdated` и в JSON-ответ, откуда фронт берёт данные для точечного обновления HP-баров.

### 9.6 `AttackService` — резолв боевых бросков

Все кубики бросает `random_int()` на сервере — никогда не AI.

```php
class AttackService
{
    private const ATTACK_BONUS_MIN = -2;
    private const ATTACK_BONUS_MAX = 10;
    private const DAMAGE_BONUS_MIN = 0;
    private const DAMAGE_BONUS_MAX = 10;
    private const PENDING_ATTACK_TTL_MINUTES = 5;

    public function __construct(private DiceService $dice) {}

    public function resolveAttack(string $attackerName, int $attackBonus, int $targetAc, string $damageDice, int $damageBonus): array
    {
        $attackBonus = max(self::ATTACK_BONUS_MIN, min(self::ATTACK_BONUS_MAX, $attackBonus));
        $damageBonus = max(self::DAMAGE_BONUS_MIN, min(self::DAMAGE_BONUS_MAX, $damageBonus));

        $d20 = random_int(1, 20);
        $isFumble = $d20 === 1;        // натуральная 1 — всегда промах
        $isCritical = $d20 === 20;      // натуральная 20 — всегда попадание + крит
        $total = $d20 + $attackBonus;
        $hit = !$isFumble && ($isCritical || $total >= $targetAc);

        $damage = 0;
        $damageRoll = null;
        if ($hit) {
            $damageRoll = $this->dice->rollDice($damageDice, $damageBonus);
            $damage = $damageRoll['total'];
            if ($isCritical) {
                $damage += array_sum($damageRoll['rolls']); // удваиваем кости (без бонуса)
            }
        }

        return [
            'attacker' => $attackerName, 'attack_roll' => $d20, 'attack_bonus' => $attackBonus,
            'attack_total' => $total, 'target_ac' => $targetAc, 'hit' => $hit,
            'critical' => $isCritical, 'fumble' => $isFumble, 'damage' => $damage,
            'damage_dice' => $damageRoll['dice'] ?? $damageDice,
        ];
    }

    // Атака NPC на игрока: AC берём из БД, не доверяем модели
    public function resolveNpcAttackOnCharacter(Room $room, string $attackerName, string $targetCharacterName, int $attackBonus, string $damageDice, int $damageBonus): ?array
    {
        $targetUserId = $room->userIdForCharacterName($targetCharacterName);
        if ($targetUserId === null) return null;

        $pivot = $room->users()->where('user_id', $targetUserId)->first()?->pivot;
        if (!$pivot) return null;

        $result = $this->resolveAttack($attackerName, $attackBonus, (int) $pivot->armor_class, $damageDice, $damageBonus);
        $result['target_user_id'] = $targetUserId;
        $result['target_character'] = $targetCharacterName;
        return $result;
    }

    public function formatMessage(array $result, string $targetName): string
    {
        $rollText = "🎲 {$result['attacker']} атакует {$targetName}: {$result['attack_roll']}+{$result['attack_bonus']}={$result['attack_total']} против AC {$result['target_ac']}";
        if ($result['fumble']) return $rollText . ' — 💀 Критический промах!';
        if (!$result['hit']) return $rollText . ' — ❌ Промах';
        $critText = $result['critical'] ? ' (🎯 Критический удар!)' : '';
        return $rollText . " — ✅ Попадание{$critText}! Урон: {$result['damage']} ({$result['damage_dice']})";
    }

    // Бонус атаки игрока: модификатор ключевой характеристики класса + флэт +2
    public function characterAttackBonus(object $pivot): int
    {
        $classToStat = [
            'fighter' => 'strength', 'barbarian' => 'strength', 'paladin' => 'strength',
            'rogue' => 'dexterity', 'ranger' => 'dexterity',
            'wizard' => 'intelligence', 'cleric' => 'wisdom', 'bard' => 'charisma',
        ];
        $stat = $classToStat[$pivot->character_class] ?? 'strength';
        $modifier = (int) floor(((int) ($pivot->{$stat} ?? 10) - 10) / 2);
        return $modifier + 2;
    }

    // "Отложенная атака": AI предлагает игроку атаковать NPC, сервер запоминает
    // цель на 5 минут, резолвится следующим /roll этого игрока в этой комнате
    public function storePendingAttack(Room $room, int $userId, array $directive): void
    {
        Cache::put($this->pendingAttackKey($room->id, $userId), $directive, now()->addMinutes(self::PENDING_ATTACK_TTL_MINUTES));
    }

    public function consumePendingAttack(Room $room, int $userId): ?array
    {
        return Cache::pull($this->pendingAttackKey($room->id, $userId)); // читает и сразу удаляет
    }

    private function pendingAttackKey(int $roomId, int $userId): string
    {
        return "pending_attack_room{$roomId}_user{$userId}";
    }
}
```

**Единственное место в проекте, где используется `Cache::` после рефакторинга.** Это осознанно: старая кэш-инвалидация (через `Cache::tags()->flush()`, которая на самом деле не работала — записи писались без тегов) была источником багов и вычищена почти везде. Здесь кэш безопасен: ключ уникален на пользователя+комнату, TTL короткий (5 минут), а при пропаже записи (истёк TTL, редис перезапустился) поведение просто деградирует до обычной d20-проверки — никакой битой бизнес-логики.

### 9.7 `DiceService` — примитив бросков

```php
class DiceService
{
    // Обычный d20 (проверка навыка). Без difficulty — "свободный" бросок с готовой интерпретацией.
    public function roll(?int $difficulty = null): array
    {
        $roll = random_int(1, 20);
        if ($difficulty !== null) {
            $success = $roll >= $difficulty;
            return [
                'roll' => $roll, 'difficulty' => $difficulty, 'success' => $success,
                'level' => $success ? '✅ Успех' : '❌ Провал',
                'message' => "🎲 **Бросок d20:** $roll (нужно $difficulty) — " . ($success ? '✅ Успех' : '❌ Провал'),
            ];
        }
        // ... критические успех/провал (1 и 20), градации 1-5/6-10/11+
    }

    // Урон произвольными костями: "1d6", "2d8" и т.п.
    public function rollDice(string $notation, int $flatBonus = 0): array
    {
        [$count, $sides] = $this->parseNotation($notation);
        $rolls = [];
        for ($i = 0; $i < $count; $i++) $rolls[] = random_int(1, $sides);
        $flatBonus = max(0, min(10, $flatBonus));
        return ['dice' => "{$count}d{$sides}", 'rolls' => $rolls, 'bonus' => $flatBonus, 'total' => array_sum($rolls) + $flatBonus];
    }

    private function parseNotation(string $notation): array
    {
        $allowedSides = [4, 6, 8, 10, 12, 20, 100];
        if (!preg_match('/^\s*(\d+)\s*[dDдД]\s*(\d+)\s*$/u', $notation, $m)) {
            return [1, 6]; // безопасный фолбэк на нераспознанную нотацию
        }
        $count = max(1, min(10, (int) $m[1]));       // не больше 10 костей за раз
        $sides = in_array((int) $m[2], $allowedSides, true) ? (int) $m[2] : 6;
        return [$count, $sides];
    }
}
```

### 9.8 `DeepSeekService` — HTTP-клиент к AI

```php
class DeepSeekService
{
    protected string $apiKey;
    protected string $apiUrl = 'https://api.deepseek.com/v1/chat/completions';

    public function __construct()
    {
        $this->apiKey = config('services.deepseek.api_key');
    }

    public function chat(array $messages, float $temperature = 0.7, int $maxTokens = 500): string
    {
        $response = Http::withHeaders(['Authorization' => 'Bearer ' . $this->apiKey])
            ->connectTimeout(5)
            ->timeout(20)
            ->post($this->apiUrl, [
                'model' => 'deepseek-chat', 'messages' => $messages,
                'temperature' => $temperature, 'max_tokens' => $maxTokens, 'stream' => false,
            ]);

        if ($response->failed()) {
            throw new \Exception('API error: ' . $response->body());
        }

        return $response->json('choices.0.message.content');
    }
}
```

Единственный сервис, обращающийся во внешний мир. `connectTimeout`/`timeout` — чтобы зависший DeepSeek не вешал HTTP-воркер бесконечно.

---

## 10. Events и Real-time (WebSockets)

### Транспорт

`Laravel Broadcasting` → `Pusher` (протокол) → `Laravel Echo` + `pusher-js` на клиенте. Канал на комнату: `room.{roomId}`, **приватный** (`PrivateChannel`, не `Channel`) — то есть подписаться может только пользователь, реально состоящий в комнате.

### `routes/channels.php`

```php
Broadcast::channel('room.{roomId}', function ($user, $roomId) {
    $room = Room::find($roomId);
    if (!$room) return false;
    return $room->isUserInRoom($user->id);
});
```

Это единственная авторизация, которую видит Pusher: без неё запрос на `/broadcasting/auth` вернёт отказ, и `Echo.private('room.X')` на клиенте не подключится.

### Почему все события `ShouldBroadcastNow`, а не `ShouldBroadcast`

`ShouldBroadcast` кладёт рассылку в очередь (`QUEUE_CONNECTION=redis`) — требует постоянно работающего `php artisan queue:work`. Если воркер не поднят (типичная ситуация на деве/небольшом проде без supervisor), события молча никуда не улетают. `ShouldBroadcastNow` шлёт синхронно в момент запроса — чуть дороже по времени ответа, зато не зависит от инфраструктуры очередей.

### Четыре события

**`GameMessageSent`** — любое новое сообщение игрового чата (реплика игрока, ответ мастера, системное сообщение о броске/атаке).

```php
class GameMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;
    public $message; public $room;

    public function broadcastOn() { return new PrivateChannel('room.' . $this->room->id); }

    public function broadcastWith()
    {
        $userName = match (true) {
            $this->message->role === 'assistant' => 'Мастер',
            $this->message->role === 'user' && $this->message->user_id =>
                $this->room->characterNameForUser($this->message->user_id) ?? $this->message->user?->name ?? 'Игрок',
            default => 'System',
        };
        return [
            'id' => $this->message->id, 'role' => $this->message->role,
            'content' => $this->message->content, 'user_name' => $userName,
            'user_id' => $this->message->user_id, 'created_at' => $this->message->created_at->timestamp,
        ];
    }
}
```

**`OocMessageSent`** — то же самое для внеигрового чата (без роли, всегда обычный игрок).

**`RoomStatusUpdated`** — полный снимок состава комнаты: статус, список игроков с HP/AC/is_ready. Шлётся на join/leave/kick/saveCharacter/start и после любых `stat_changes`. Фронт по этому событию перерисовывает весь список игроков.

**`CharacterStatsUpdated`** — точечные дельты (кто получил урон/лечение/левел-ап), без полного списка. Фронт по нему обновляет HP-бар КОНКРЕТНОГО игрока и показывает всплывающий тост, не трогая остальной DOM.

```php
class CharacterStatsUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;
    public Room $room; public array $changes;

    public function broadcastOn() { return new PrivateChannel('room.' . $this->room->id); }
    public function broadcastWith() { return ['changes' => $this->changes]; }
}
```

### Кто и когда broadcast'ит

| Событие | Откуда |
|---|---|
| `GameMessageSent` | `GameChatService::sendMessage()` (реплика игрока, системный ролл, ответ мастера, боевые сообщения), `RoomService::start()` (вступление) |
| `OocMessageSent` | `OocChatService::sendMessage()` |
| `RoomStatusUpdated` | `RoomService` (join/leave/kickAll/saveCharacter/start), `GameChatService` (после ненулевых `stat_changes`) |
| `CharacterStatsUpdated` | `GameChatService` (после ненулевых `stat_changes`) |

`->toOthers()` вызывается там, где инициатор действия уже получает результат напрямую из HTTP-ответа (не нужно ждать WS, чтобы не задваивать) — `RoomStatusUpdated`/`CharacterStatsUpdated` шлются БЕЗ `->toOthers()`, потому что инициатор нового HP тоже должен получить обновление своих соседей по команде.

---

## 11. Боевая механика и протокол AI-директив

### Идея

Модель никогда не считает и не выдумывает числа — она только описывает **намерение**. Три типа служебных блоков, которые модель обязана добавлять последней строкой (или несколькими строками) ответа:

#### `STATE` — изменение без броска (зелья, эффекты окружения, левел-ап)

```
[[STATE:{"changes":[{"character":"Арагорн","hp_delta":15}]}]]
```
Обязателен в КАЖДОМ ответе (даже пустой: `{"changes":[]}`) — это правило нужно, чтобы модель не забывала думать о последствиях своих же слов.

Поля: `character` (точное имя), `hp_delta` (−50..50), `ac_delta` (−10..10, опционально), `max_hp_delta` (0..50, только левел-ап, опционально).

#### `ATTACK` — NPC атакует игрока прямо сейчас

```
[[ATTACK:{"attacks":[{"attacker":"Гоблин","target":"Арагорн","attack_bonus":3,"damage_dice":"1d6","damage_bonus":1}]}]]
```
Сервер сам берёт актуальный AC цели из БД (`Room::userIdForCharacterName` → `armor_class` из pivot), бросает d20+attack_bonus, при попадании — урон по `damage_dice`+`damage_bonus`. Модель НЕ указывает `target_ac` — не может его "занизить".

#### `PENDING_ATTACK` — игрок атакует NPC (резолвится следующим `/roll`)

```
[[PENDING_ATTACK:{"npc_name":"Гоблин","target_ac":13,"damage_dice":"1d8","damage_bonus":2}]]
```
Здесь `target_ac` ОБЯЗАН указать AI — у NPC нет записи в БД, это единственный источник его защиты. Хранится в Redis 5 минут (`AttackService::storePendingAttack`), резолвится в `GameChatService::resolveRoll()` при следующем `/roll` этого игрока: бонус атаки берётся из класса персонажа (`AttackService::characterAttackBonus`), а не от AI.

### Полный текст промпта (актуальная версия, `buildSystemPrompt`)

```
Ты — Мастер Подземелий в Dungeons & Dragons. Ты ведёшь игру для группы.

**ВАЖНО: Различай игроков!**
...

Персонажи в игре (с текущим HP и AC):
{$playersList}

{$currentPlayerInfo}

ПРАВИЛА:
1. Отвечай КРАТКО (2-4 предложения)
2. Создавай интересные вызовы и развивай сюжет
3. Когда нужна проверка навыка (НЕ атака), скажи: "Для этого нужна проверка [навыка]. Сложность: X"
4. Давай игрокам выбор (2-3 варианта)
5. НИКОГДА не отвечай за персонажей игроков
6. Ты управляешь NPC
7. Учитывай текущее состояние HP и AC в описаниях
8. Обращайся к персонажам по их именам
9. Помни, кто сейчас действующий игрок

БОЕВАЯ МЕХАНИКА (ОБЯЗАТЕЛЬНО, ТРИ ТИПА СЛУЖЕБНЫХ БЛОКОВ):

Броски кубиков ты НИКОГДА не считаешь и не придумываешь сам — их бросает сервер.
Твоя задача — только описать намерение... Никогда не пиши в тексте сцены готовый
результат броска ("ты попадаешь и наносишь 8 урона") — опиши только сам момент
атаки, а числа появятся из блока.

1) STATE ... (см. выше, полный текст с примерами и диапазонами)
2) ATTACK ... (полный текст с ориентирами по attack_bonus: слабый враг 1-3,
   средний 3-6, опасный 6-10)
3) PENDING_ATTACK ... (полный текст, включая указание, что AC цели подбирает AI)

ВАЖНО: если урон/лечение уже относится к блоку ATTACK или PENDING_ATTACK —
НЕ дублируй его в STATE, сервер сам применит урон от боевых бросков.

Мастер-промт: {$basePrompt}
```

**Итерация промпта:** изначальная версия описывала только STATE и была недостаточно строгой — модель регулярно НАРРАТИВНО описывала лечение/урон ("рана затягивается"), не отражая это цифрой. Промпт был усилен явным запретом ("текст без цифры — ошибка, а не стиль повествования") и ориентирами по величине урона/лечения по категориям (лёгкий/серьёзный/тяжёлый). Это классическое ограничение LLM-driven механик: инструкция не гарантия, только снижает частоту пропусков.

### Пример полного цикла: NPC атакует игрока

1. Игрок пишет `"бью тролля мечом"` → `GameChatService::sendMessage()` создаёт `GameMessage(role=user)`, транслирует `GameMessageSent`.
2. `GameMasterService::processMessage()` собирает контекст, шлёт в DeepSeek.
3. Ответ модели: `"Твой удар достигает цели, но тролль в ярости отвечает мощным ударом дубины!\n\n[[ATTACK:{"attacks":[{"attacker":"Тролль","target":"Арагорн","attack_bonus":5,"damage_dice":"2d6","damage_bonus":2}]}]]\n[[STATE:{"changes":[]}]]"`.
4. `extractDirectives()` вырезает оба блока с конца, `content` остаётся чистым нарративным текстом.
5. Создаётся `GameMessage(role=assistant)` с чистым текстом.
6. Цикл по `ATTACK`: `AttackService::resolveNpcAttackOnCharacter()` находит `user_id` Арагорна по имени, берёт его `armor_class` из БД, бросает `d20+5` против AC. Допустим, попадание → бросает `2d6+2` урона.
7. Создаётся `GameMessage(role=system, content="🎲 Тролль атакует Арагорна: 14+5=19 против AC 15 — ✅ Попадание! Урон: 9 (2d6+2)")`.
8. `CombatService::applyStateChanges()` списывает 9 HP у Арагорна в `room_user`.
9. `GameChatService` рассылает по WebSocket: `GameMessageSent` (реплика мастера), `GameMessageSent` (системное сообщение с броском), `CharacterStatsUpdated` (дельта −9 HP), `RoomStatusUpdated` (полный снимок).
10. Фронт (`room.js`) рендерит оба сообщения в чат, обновляет HP-бар Арагорна точечно, показывает тост «💥 Арагорн: −9 HP».

---

## 12. Frontend: room.js

Один файл, один класс `DnDRoom`, без сборки на фреймворке — ванильный JS с `fetch`/DOM API. Импортируется глобально через `app.js`, но инициализируется только если на странице есть `[data-room-id]` — на остальных страницах (логин, список комнат) вообще не создаёт WebSocket-соединения.

### Инициализация (лениво, только на странице комнаты)

```js
document.addEventListener('DOMContentLoaded', () => {
    const el = document.querySelector('[data-room-id]');
    if (!el) return; // не комната — ничего не делаем

    const room = new DnDRoom(el.dataset.roomId, el.dataset.userId);
    window.addEventListener('pagehide', () => room.disconnect());
});
```

Внутри конструктора создаются `Echo`/`Pusher` (тоже лениво — раньше это делалось на уровне модуля, то есть на каждой странице сайта):

```js
window.Echo = new Echo({
    broadcaster: 'pusher',
    key: '7ad02cc7a1ec4d3967c9',
    cluster: 'eu',
    forceTLS: true,
    authEndpoint: '/broadcasting/auth',
    auth: { headers: { 'X-CSRF-TOKEN': this.csrfToken } }, // без этого приватный канал не авторизуется
});
```

### Методы класса (карта)

| Метод | Отвечает за |
|---|---|
| `initWebSockets()` | Подписка на `room.{id}` (private), 4 листенера — по одному на каждое событие из раздела 10 |
| `initGameChat()` | Сабмит формы игрового чата: fetch → рендер из ответа НАПРЯМУЮ (не ждём WS) + флаг `sendingGameMessage` против дабл-сабмита |
| `initOocChat()` | То же для OOC-чата |
| `initDiceRoll()` | Клик по 🎲 → подставляет `/roll` в инпут и сабмитит форму (без блокирующего `prompt()`, который раньше был) |
| `addGameMessage(msg)` / `addOocMessage(msg)` | Рендер одного сообщения в DOM; дедуп по `data-message-id` — важно, потому что одно и то же сообщение может прийти и из ответа fetch, и из WS |
| `updatePlayersList(users)` | Полная перерисовка списка игроков (по `RoomStatusUpdated`) |
| `applyStatChange(change)` | Точечное обновление HP-бара/текста ОДНОГО игрока без перерисовки списка (по `CharacterStatsUpdated`) + тост |
| `showStatToast(change)` | Всплывающее уведомление "💥 Имя: −N HP" / "⭐ левел-ап!" |
| `showRollResult(roll)` | Карточка результата броска (переиспользуется и для обычных проверок, и для боевых бросков — формат одинаковый) |
| `updateRoomStatus(data)` | Меняет бейдж статуса комнаты, разблокирует инпуты при переходе в `playing` |
| `disconnect()` | `Echo.leave()` + `Echo.disconnect()` при уходе со страницы |

### Почему рендер идёт и из ответа fetch, и из WebSocket одновременно

```js
const data = await response.json();
if (data.user_message) this.addGameMessage(data.user_message);
if (data.system_message) this.addGameMessage(data.system_message);
(data.combat_messages || []).forEach((m) => this.addGameMessage(m));
if (data.ai_message) this.addGameMessage(data.ai_message);
(data.stat_changes || []).forEach((change) => this.applyStatChange(change));
```

Отправитель сообщения получает результат СРАЗУ из HTTP-ответа, не дожидаясь WS (на нестабильном мобильном соединении событие иногда теряется/опаздывает). Остальные участники комнаты получают то же самое через `GameMessageSent`/`CharacterStatsUpdated` по WebSocket. Дедупликация по `id` в `addGameMessage`/`addOocMessage` гарантирует, что если оба пути всё-таки доставят одно и то же сообщение отправителю — оно не задвоится в DOM.

---

## 13. Blade-шаблоны

| Файл | Назначение |
|---|---|
| `layouts/app.blade.php` | Общий layout: `@vite`, флеш-тосты (`session('success')`/`error`/`warning`) с авто-исчезновением через `setTimeout` |
| `layouts/navigation.blade.php` | Верхнее меню |
| `rooms/index.blade.php` | Список комнат с пагинацией, кнопка "Присоединиться"/"Войти" в зависимости от `$userRooms` |
| `rooms/create.blade.php` | Форма создания комнаты |
| `rooms/show.blade.php` | Главный экран: игровой чат, OOC-чат, список игроков с HP-барами, модалка создания персонажа |
| `rooms/confirm-destroy.blade.php` | Подтверждение удаления комнаты |

### `show.blade.php` — ключевые моменты разметки

Список игроков рендерится в SSR ТОЙ ЖЕ структурой DOM, что и JS-метод `renderPlayerRow()` — важно, потому что `applyStatChange()` ищет элементы по классам `.hp-text`/`.hp-bar` внутри `[data-user-id]`, и эти классы должны существовать сразу после первой отрисовки страницы, а не только после первого WS-события:

```blade
@php
    $maxHp = $user?->pivot->max_hp ?: 1;
    $currentHp = max(0, min($maxHp, $user?->pivot->current_hp ?? $maxHp));
    $hpPct = (int) round(($currentHp / $maxHp) * 100);
    $hpBarColor = $hpPct <= 25 ? 'bg-red-500' : ($hpPct <= 60 ? 'bg-yellow-500' : 'bg-green-500');
@endphp
<div class="..." data-user-id="{{ $user->id }}">
    ...
    <div class="text-xs text-gray-400 truncate hp-text">
        HP: {{ $currentHp }}/{{ $maxHp }} | AC: {{ $user?->pivot->armor_class }}
    </div>
    <div class="w-full bg-gray-900 rounded-full h-1.5 mt-1 overflow-hidden">
        <div class="hp-bar h-1.5 rounded-full transition-all duration-500 {{ $hpBarColor }}" style="width: {{ $hpPct }}%"></div>
    </div>
</div>
```

Резолв имени персонажа в истории сообщений — через `Room::characterNameForUser()`, а не через `$msg->user->pivot` (см. предупреждение в разделе 3):

```blade
@php
    $displayName = match(true) {
        $msg->role === 'assistant' => '🎲 Мастер',
        $msg->role === 'system' => 'System',
        (bool) $msg->user_id => $room->characterNameForUser($msg->user_id) ?? 'System',
        default => 'System',
    };
@endphp
```

---

## 14. Маршруты

### `routes/web.php` (внутри `middleware('auth')`)

```php
Route::resource('rooms', RoomController::class);
Route::post('/rooms/{room}/join', [RoomController::class, 'join'])->name('rooms.join');
Route::post('/rooms/{room}/character', [RoomController::class, 'saveCharacter'])->name('rooms.character.save');
Route::post('/rooms/{room}/start', [RoomController::class, 'start'])->name('rooms.start');

Route::get('/rooms/{room}/game-messages', [GameMessageController::class, 'index'])->name('game-messages.index');
Route::post('/rooms/{room}/game-messages', [GameMessageController::class, 'store'])
    ->middleware('throttle:20,1')->name('game-messages.store');

Route::get('/rooms/{room}/ooc-messages', [OocMessageController::class, 'index'])->name('ooc-messages.index');
Route::post('/rooms/{room}/ooc-messages', [OocMessageController::class, 'store'])
    ->middleware('throttle:30,1')->name('ooc-messages.store');

Route::post('/rooms/{room}/leave', [RoomController::class, 'leave'])->name('rooms.leave');
Route::get('/rooms/{room}/confirm-destroy', [RoomController::class, 'confirmDestroy'])->name('rooms.destroy.confirm');
Route::delete('/rooms/{room}', [RoomController::class, 'destroy'])->name('rooms.destroy');
Route::post('/rooms/{room}/kick-all', [RoomController::class, 'kickAll'])->name('rooms.kick.all');
Route::get('/rooms/{room}/status', [RoomController::class, 'status'])->name('rooms.status');
```

`throttle:20,1` / `throttle:30,1` — защита от спама платных AI-запросов (каждое игровое сообщение = вызов DeepSeek).

### `routes/channels.php`

См. раздел 10 — единственный кастомный канал `room.{roomId}` плюс стандартный `App.Models.User.{id}` из коробки Laravel.

---

## 15. Конфигурация

### `bootstrap.js` — только axios

```js
import axios from 'axios';
window.axios = axios;
window.axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest';
```

Echo/Pusher намеренно НЕ здесь — раньше тут создавался второй, неавторизованный экземпляр Echo, который `room.js` тут же перезатирал через `window.Echo = ...`, но открытое им WS-соединение оставалось висеть до закрытия вкладки — на каждой странице сайта, не только в комнате.

### `tailwind.config.js` — важная деталь про `content`

```js
content: [
    './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
    './storage/framework/views/*.php',
    './resources/views/**/*.blade.php',
    './resources/js/**/*.js',   // ⚠️ КРИТИЧНО
],
```

Строка `./resources/js/**/*.js` обязательна. Tailwind JIT ищет имена классов текстовым сканированием указанных файлов — если класс используется ТОЛЬКО в `room.js` (например, динамически собранный `className` тоста) и нигде в Blade, без этой строки в `content` класс просто не попадёт в собранный CSS, и элемент отрендерится без стилей (реальный баг, который был здесь: класс `top-16` для тоста урона не генерировался, тост появлялся, но без позиционирования — то есть визуально "не появлялся").

### `config/database.php` — Redis с раздельными БД под разные нужды

```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'),
    'default' => [..., 'database' => env('REDIS_DB', '0'), 'prefix' => 'laravel_default_'],
    'cache'   => [..., 'database' => env('REDIS_CACHE_DB', '1'), 'prefix' => 'cache_'],
    'session' => [..., 'database' => 2, 'prefix' => 'sess_'],
    'queue'   => [..., 'database' => 3, 'prefix' => 'queue_'],
],
```

Разделение по номерам БД + префиксам — чтобы `FLUSHDB` или ручная чистка одного назначения (например, кэша) не задевала сессии или очередь.

---

## 16. Сценарии целиком (request lifecycle)

### Сценарий A: игрок создаёт персонажа

```
POST /rooms/15/character
  → middleware('auth')
  → SaveCharacterRequest::authorize()
      → Auth::user()->can('manageCharacter', $room)
          → RoomPolicy::manageCharacter(): isUserInRoom && status === 'waiting'
  → SaveCharacterRequest::rules() — валидация всех характеристик 3..20
  → RoomController::saveCharacter($request, $room)
      → RoomService::saveCharacter($room, $userId, $data)
          → вычисляет max_hp = 30 + mod(CON)*2, armor_class = 10 + mod(DEX)
          → room_user.updateExistingPivot(...): character_name, class, статы, is_ready=true
          → broadcast(RoomStatusUpdated) — все в комнате видят нового игрока в списке
  → redirect back с success-флешем
```

### Сценарий B: игрок пишет `/roll` после того, как AI предложил атаку

```
1. Предыдущий ход: AI ответил с [[PENDING_ATTACK:{"npc_name":"Гоблин","target_ac":13,...}]]
   → GameMasterService::processMessage() сохранил директиву:
     AttackService::storePendingAttack($room, $userId, [...]) → Cache::put(TTL 5 мин)

2. Игрок жмёт 🎲 → JS подставляет "/roll" → POST /rooms/15/game-messages

3. StoreGameMessageRequest::authorize() → RoomPolicy::sendMessage()
   (isUserInRoom && status === 'playing')

4. GameMessageController::store() → GameChatService::sendMessage()
   → isRoll === true → resolveRoll()
       → AttackService::consumePendingAttack() — читает И УДАЛЯЕТ запись
       → есть pendingAttack с target_ac → это боевой бросок:
           attackBonus = characterAttackBonus(pivot)  // класс → характеристика → модификатор+2
           resolveAttack(характер, attackBonus, target_ac, dice, bonus)
               → random_int(1,20), сравнение с AC, при попадании — random_int по костям урона
       → возвращает $rollData в формате, совместимом с обычным броском
   → создаётся GameMessage(role=system) с текстом броска, broadcast(GameMessageSent)
   → GameMasterService::processMessage() вызывается СНОВА — AI реагирует на исход боя
     (rollResult передаётся системным сообщением в контекст)
   → ответ мастера может сам содержать новый ATTACK (например, NPC контратакует)

5. JSON-ответ содержит: system_message (бросок), ai_message (реакция мастера),
   combat_messages (если контратака), stat_changes (если кто-то получил урон)
```

### Сценарий C: мастер начинает игру

```
POST /rooms/15/start
  → RoomController::start() → $this->authorize('start', $room) → created_by === Auth::id()
  → RoomService::start($room)
      → проверка: >= 2 игрока с is_ready=true, иначе RuntimeException
      → room.status = 'playing'
      → broadcast(RoomStatusUpdated) — у всех разблокируются инпуты чата
      → GameMasterService::generateIntro($room) — отдельный, более простой промпт
        (без истории, без директив — просто вступительная сцена)
      → broadcast(GameMessageSent) — интро мастера появляется в чате у всех сразу
  → redirect back с success
```

---

## 17. Известные ограничения и технический долг

Список того, что осознанно НЕ сделано в текущей итерации — чтобы следующий разработчик не тратил время на повторное обнаружение:

1. **`app/Http/Controllers/Api/*`** — API-ветка контроллеров (`AuthController`, `RoomController`) НЕ отрефакторена, дублирует часть логики web-контроллеров, не использует `RoomPolicy`/сервисы. Живёт независимо, не синхронизирована с текущей архитектурой.

2. **`app/Console/Commands/CleanOldRooms.php`** — cron-команда очистки старых комнат работает напрямую с Eloquent внутри Artisan-команды, бизнес-логику из неё не выносили в сервис. Работает, но непереиспользуема и плохо тестируема в текущем виде.

3. **Имя файла события `RoomStatusUpdated`** — физически называется `RoomStatusUpdate.php` (без `d` на конце), при этом класс внутри — `RoomStatusUpdated`. PSR-4 автозагрузка Laravel это не ломает (матчит по неймспейсу/классу через Composer classmap), но для читаемости стоит переименовать файл.

4. **Нет enum'ов.** `status` комнаты (`waiting`/`playing`/`finished`), `role` сообщения (`user`/`assistant`/`system`), `character_class` — везде строковые литералы, разбросанные по PHP/Blade/JS. Один опечатанный литерал в новом месте — и сравнение молча не сработает. Стоит завести `RoomStatus`, `MessageRole`, `CharacterClass` как backed enum.

5. **Протокол AI-директив — не гарантия.** `[[STATE/ATTACK/PENDING_ATTACK]]` — это инструкция модели, не контракт. Модель может забыть блок, поставить его не последней строкой (тогда регулярка не смэтчит), прислать невалидный JSON. Всё это обрабатывается как "нет изменений" (fail-safe, чат не падает), но иногда это будет выглядеть как "атака NPC не сработала" без явной причины для игрока. Мониторить `Log::warning` в `GameMasterService`/`CombatService`/`AttackService`.

6. **`PENDING_ATTACK` живёт 5 минут в Redis без UI-индикации.** Если игрок долго думает перед `/roll`, отложенная атака тихо протухнет, и `/roll` откатится к обычной d20-проверке — для игрока это будет выглядеть как "мастер предложил атаку, а бросок оказался обычным". Явного предупреждения на фронте нет.

7. **Нет тестов.** Ни unit, ни feature — рефакторинг делался вручную с ручной проверкой (в том числе через реальные игровые сессии). Это самый большой риск при дальнейших изменениях `CombatService`/`AttackService`/`GameMasterService`.

8. **Урон по NPC не персистентен.** У NPC нет записи в БД (не сущности), поэтому `PENDING_ATTACK`-урон по NPC существует только в тексте системного сообщения и в контексте следующего запроса к AI — если понадобится трекать HP NPC между ходами (для длинных боёв), нужна отдельная таблица/структура.

9. **Rate limiting только на уровне HTTP (`throttle`).** Нет отдельного лимита на стоимость AI-запросов в масштабе комнаты/суток — при желании защититься от дорогого злоупотребления одним активным пользователем понадобится отдельный счётчик (например, через Redis) поверх `throttle`.

10. **`RoomUser::modifier($stat)`** существует в модели, но не используется явно нигде в коде — вся математика модификаторов дублируется inline (`floor(($x - 10) / 2)`) в `RoomService`, `AttackService`. Стоит либо вызывать существующий метод, либо удалить его как мёртвый код.
