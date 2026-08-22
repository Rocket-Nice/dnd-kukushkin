import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

const CLASS_NAME_MAP = {
    'fighter': 'Воин',
    'wizard': 'Волшебник',
    'rogue': 'Плут',
    'cleric': 'Жрец',
    'ranger': 'Следопыт',
    'paladin': 'Паладин',
    'bard': 'Бард',
    'barbarian': 'Варвар'
};

class DnDRoom {
    constructor(roomId, userId) {
        console.log('DnDRoom initialized', { roomId, userId });

        this.roomId = roomId;
        this.userId = userId;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;

        this.sendingGameMessage = false;
        this.sendingOocMessage = false;

        // Echo/Pusher создаются только здесь — то есть только когда мы точно
        // находимся на странице комнаты. Раньше это происходило на уровне
        // модуля (при импорте room.js), а значит на КАЖДОЙ странице сайта,
        // включая логин/профиль/список комнат, где WS вообще не нужен.
        window.Pusher = Pusher;
        window.Echo = new Echo({
            broadcaster: 'pusher',
            key: '7ad02cc7a1ec4d3967c9',
            cluster: 'eu',
            forceTLS: true,
            authEndpoint: '/broadcasting/auth',
            auth: {
                headers: {
                    'X-CSRF-TOKEN': this.csrfToken,
                },
            },
        });

        this.init();
        this.initWebSockets();
        this.scrollToBottom('game-chat');
        this.scrollToBottom('ooc-chat');
    }

    init() {
        this.initGameChat();
        this.initOocChat();
        this.initDiceRoll();
    }

    initWebSockets() {
        window.Echo.private(`room.${this.roomId}`)
            .listen('GameMessageSent', (e) => {
                this.addGameMessage(e);
            })
            .listen('OocMessageSent', (e) => {
                this.addOocMessage(e);
            })
            .listen('RoomStatusUpdated', (e) => {
                this.updateRoomStatus(e);
                this.updatePlayersList(e.users);
            })
            .listen('CharacterStatsUpdated', (e) => {
                (e.changes || []).forEach((change) => this.applyStatChange(change));
            })
            .error((error) => {
                console.error('Echo channel auth/error:', error);
            });

        console.log('WebSockets initialized for room:', this.roomId);
    }

    /**
     * Отписывается от приватного канала и закрывает соединение — вызывается
     * при уходе со страницы комнаты (см. обработчик в конце файла), чтобы не
     * держать открытый WS-сокет, гуляя по остальному сайту через Alpine/SPA-подобную
     * навигацию (если она появится) или просто на всякий случай при unload.
     */
    disconnect() {
        window.Echo?.leave(`room.${this.roomId}`);
        window.Echo?.disconnect();
    }

    updateRoomStatus(data) {
        const statusElement = document.querySelector('.status-badge');
        if (statusElement) {
            const statusText = data.status === 'waiting' ? 'Ожидание' : (data.status === 'playing' ? 'В игре' : 'Завершена');
            const statusClass = data.status === 'waiting' ? 'bg-yellow-600' : (data.status === 'playing' ? 'bg-green-600' : 'bg-gray-600');

            statusElement.textContent = statusText;
            statusElement.className = `status-badge px-2 py-1 rounded text-xs ${statusClass}`;
        }

        if (data.status === 'playing') {
            const gameInput = document.getElementById('game-message-input');
            const gameSubmit = document.querySelector('#game-message-form button[type="submit"]');
            const rollBtn = document.getElementById('roll-dice');

            if (gameInput) gameInput.disabled = false;
            if (gameSubmit) gameSubmit.disabled = false;
            if (rollBtn) rollBtn.disabled = false;
        }
    }

    hpBarColor(pct) {
        if (pct <= 25) return 'bg-red-500';
        if (pct <= 60) return 'bg-yellow-500';
        return 'bg-green-500';
    }

    renderPlayerRow(user) {
        const readyClass = user.is_ready ? 'bg-green-900 bg-opacity-20' : 'bg-gray-700';
        const readyIcon = user.is_ready ? '<span class="ml-1 text-xs text-green-400">✅</span>' : '';

        let className = '';
        if (user.character_class) {
            className = CLASS_NAME_MAP[user.character_class] || user.character_class;
        }

        let statsBlock = '';
        if (user.character_name) {
            const maxHp = user.max_hp || 1;
            const currentHp = Math.max(0, Math.min(maxHp, user.current_hp ?? maxHp));
            const pct = Math.round((currentHp / maxHp) * 100);

            statsBlock = `
                <div class="text-xs text-gray-400 truncate hp-text">
                    ${className ? '<span class="text-purple-400">' + this.escapeHtml(className) + '</span> | ' : ''}
                    HP: ${currentHp}/${maxHp} | AC: ${user.armor_class ?? '—'}
                </div>
                <div class="w-full bg-gray-900 rounded-full h-1.5 mt-1 overflow-hidden">
                    <div class="hp-bar h-1.5 rounded-full transition-all duration-500 ${this.hpBarColor(pct)}" style="width:${pct}%"></div>
                </div>
            `;
        }

        const div = document.createElement('div');
        div.className = `flex items-center justify-between p-2 ${readyClass} rounded-lg`;
        div.dataset.userId = user.id;

        div.innerHTML = `
            <div class="flex items-center space-x-2 min-w-0 flex-1">
                <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-gray-600 flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0">
                    ${this.escapeHtml((user.character_name || user.name).charAt(0))}
                </div>
                <div class="min-w-0 flex-1">
                    <div class="font-medium text-xs sm:text-sm truncate">
                        ${this.escapeHtml(user.character_name || user.name)} ${readyIcon}
                    </div>
                    ${statsBlock}
                </div>
            </div>
        `;

        return div;
    }

    updatePlayersList(users) {
        const container = document.querySelector('.players-container');
        if (!container) return;

        const headerElement = document.querySelector('.players-header');
        if (headerElement) {
            const maxPlayers = headerElement.dataset.max || '2';
            headerElement.innerHTML = `Участники <span class="players-count">${users.length}</span>/${maxPlayers}`;
        }

        container.innerHTML = '';

        if (users.length === 0) {
            container.innerHTML = '<div class="text-center text-gray-500 py-4">Нет участников</div>';
            return;
        }

        users.forEach(user => {
            container.appendChild(this.renderPlayerRow(user));
        });
    }

    /**
     * Точечно обновляет HP-бар/текст конкретного игрока (без перерисовки всего
     * списка) и показывает всплывающий тост урона/лечения/левел-апа.
     */
    applyStatChange(change) {
        const row = document.querySelector(`.players-container [data-user-id="${change.user_id}"]`);
        if (row) {
            const hpText = row.querySelector('.hp-text');
            const hpBar = row.querySelector('.hp-bar');
            const pct = Math.max(0, Math.min(100, Math.round((change.current_hp / change.max_hp) * 100)));

            if (hpText) {
                const classLabel = hpText.querySelector('span')?.outerHTML || '';
                hpText.innerHTML = `${classLabel} HP: ${change.current_hp}/${change.max_hp} | AC: ${change.armor_class}`;
            }

            if (hpBar) {
                hpBar.style.width = pct + '%';
                hpBar.className = `hp-bar h-1.5 rounded-full transition-all duration-500 ${this.hpBarColor(pct)}`;
            }
        }

        if (change.hp_delta) {
            this.showStatToast(change);
        } else if (change.leveled_up) {
            this.showStatToast({ ...change, hp_delta: 0 }, true);
        }
    }

    showStatToast(change, isLevelUp = false) {
        const isDamage = change.hp_delta < 0;
        const div = document.createElement('div');
        div.className = `fixed top-16 right-4 ${isLevelUp ? 'bg-purple-600' : (isDamage ? 'bg-red-600' : 'bg-green-600')} text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in text-sm`;

        const label = isLevelUp
            ? `⭐ ${this.escapeHtml(change.character_name)}: левел-ап!`
            : `${isDamage ? '💥' : '💚'} ${this.escapeHtml(change.character_name)}: ${change.hp_delta > 0 ? '+' : ''}${change.hp_delta} HP`;

        div.textContent = label;
        document.body.appendChild(div);
        setTimeout(() => div.remove(), 3500);
    }

    initGameChat() {
        const form = document.getElementById('game-message-form');
        if (!form) return;

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (this.sendingGameMessage) return;

            const input = document.getElementById('game-message-input');
            const submitBtn = form.querySelector('button[type="submit"]');
            const rollBtn = document.getElementById('roll-dice');
            const message = input.value.trim();

            if (!message) return;

            this.sendingGameMessage = true;
            input.disabled = true;
            submitBtn.disabled = true;
            if (rollBtn) rollBtn.disabled = true;

            try {
                const response = await fetch(`/rooms/${this.roomId}/game-messages`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ message })
                });

                const data = await response.json();

                if (response.ok) {
                    input.value = '';

                    if (data.user_message) this.addGameMessage(data.user_message);
                    if (data.system_message) this.addGameMessage(data.system_message);
                    (data.combat_messages || []).forEach((m) => this.addGameMessage(m));
                    if (data.ai_message) this.addGameMessage(data.ai_message);
                    if (data.roll) this.showRollResult(data.roll);
                    (data.stat_changes || []).forEach((change) => this.applyStatChange(change));
                } else {
                    this.showError(data.error || 'Ошибка при отправке сообщения');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showError('Ошибка соединения');
            } finally {
                this.sendingGameMessage = false;
                input.disabled = false;
                submitBtn.disabled = false;
                if (rollBtn) rollBtn.disabled = false;
                input.focus();
            }
        });
    }

    initOocChat() {
        const form = document.getElementById('ooc-message-form');
        if (!form) return;

        const input = document.getElementById('ooc-message-input');
        const submitBtn = form.querySelector('button[type="submit"]');

        form.addEventListener('submit', async (e) => {
            e.preventDefault();

            if (this.sendingOocMessage) return;

            const message = input.value.trim();
            if (!message) return;

            this.sendingOocMessage = true;
            input.disabled = true;
            submitBtn.disabled = true;

            try {
                const response = await fetch(`/rooms/${this.roomId}/ooc-messages`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrfToken,
                        'Accept': 'application/json'
                    },
                    body: JSON.stringify({ message })
                });

                const data = await response.json();

                if (response.ok) {
                    input.value = '';
                    if (data.message) this.addOocMessage(data.message);
                } else {
                    this.showError(data.error || 'Ошибка при отправке сообщения');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showError('Ошибка соединения');
            } finally {
                this.sendingOocMessage = false;
                input.disabled = false;
                submitBtn.disabled = false;
                input.focus();
            }
        });
    }

    initDiceRoll() {
        const rollBtn = document.getElementById('roll-dice');
        if (!rollBtn) return;

        rollBtn.addEventListener('click', () => {
            if (rollBtn.disabled) return;

            const input = document.getElementById('game-message-input');
            input.value = '/roll';

            document.getElementById('game-message-form').dispatchEvent(new Event('submit', { cancelable: true }));
        });
    }

    addGameMessage(msg) {
        const chat = document.getElementById('game-chat');
        if (!chat) return;

        const emptyMessage = document.getElementById('game-empty-message');
        if (emptyMessage) {
            emptyMessage.remove();
        }

        const existingMessages = chat.querySelectorAll('[data-message-id]');
        for (let existing of existingMessages) {
            if (existing.dataset.messageId == msg.id) {
                return;
            }
        }

        const div = document.createElement('div');
        div.className = `flex ${msg.role === 'assistant' ? 'justify-start' : 'justify-end'}`;
        div.dataset.messageId = msg.id;
        div.dataset.timestamp = msg.created_at;

        let bg = 'bg-gray-700';
        let textColor = 'text-gray-100';
        let nameColor = 'text-gray-400';
        let timeColor = 'text-gray-500';

        if (msg.role === 'system') {
            bg = 'bg-yellow-600 bg-opacity-20 border border-yellow-700';
            textColor = 'text-yellow-200';
            nameColor = 'text-yellow-300';
            timeColor = 'text-yellow-400';
        } else if (msg.role === 'user') {
            bg = 'bg-purple-600';
            textColor = 'text-white';
            nameColor = 'text-purple-200';
            timeColor = 'text-purple-300';
        }

        let displayName = 'System';
        if (msg.role === 'assistant') {
            displayName = '🎲 Мастер';
        } else if (msg.user_name && msg.user_name !== 'System' && msg.user_name !== '') {
            displayName = this.escapeHtml(msg.user_name);
        }

        div.innerHTML = `
            <div class="max-w-[90%] sm:max-w-[80%] ${bg} ${textColor} rounded-lg px-3 sm:px-4 py-2 shadow">
                <div class="text-xs ${nameColor} mb-1 font-semibold">
                    ${displayName}
                </div>
                <div class="text-xs sm:text-sm break-words">${this.escapeHtml(msg.content)}</div>
                <div class="text-xs ${timeColor} text-right mt-1">
                    ${new Date(msg.created_at * 1000).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}
                </div>
            </div>
        `;

        chat.appendChild(div);
        this.scrollToBottom('game-chat');
    }

    addOocMessage(msg) {
        const chat = document.getElementById('ooc-chat');
        if (!chat) return;

        const emptyMessage = document.getElementById('ooc-empty-message');
        if (emptyMessage) {
            emptyMessage.remove();
        }

        const existingMessages = chat.querySelectorAll('[data-message-id]');
        for (let existing of existingMessages) {
            if (existing.dataset.messageId == msg.id) {
                return;
            }
        }

        const div = document.createElement('div');
        div.className = 'text-sm ooc-message';
        div.dataset.messageId = msg.id;
        div.dataset.timestamp = msg.created_at;

        div.innerHTML = `
            <span class="font-medium text-blue-400">${this.escapeHtml(msg.user_name)}:</span>
            <span class="text-gray-300 ml-1">${this.escapeHtml(msg.content)}</span>
            <span class="text-xs text-gray-500 ml-2">${new Date(msg.created_at * 1000).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })}</span>
        `;

        chat.appendChild(div);
        this.scrollToBottom('ooc-chat');
    }

    scrollToBottom(elementId) {
        const el = document.getElementById(elementId);
        if (el) {
            setTimeout(() => {
                el.scrollTop = el.scrollHeight;
            }, 100);
        }
    }

    showError(message) {
        const errorDiv = document.createElement('div');
        errorDiv.className = 'fixed top-4 right-4 bg-red-600 text-white px-4 py-2 rounded-lg shadow-lg z-50 animate-fade-in';
        errorDiv.textContent = message;
        document.body.appendChild(errorDiv);
        setTimeout(() => errorDiv.remove(), 3000);
    }

    showRollResult(roll) {
        const notification = document.createElement('div');
        notification.className = 'fixed bottom-4 right-4 bg-gray-800 border border-gray-700 rounded-lg shadow-xl p-4 max-w-sm z-50 animate-fade-in';

        const successColor = roll.success ? 'bg-green-600' : 'bg-red-600';

        notification.innerHTML = `
            <div class="flex items-start space-x-3">
                <div class="text-2xl">🎲</div>
                <div class="flex-1">
                    <h4 class="font-bold text-white">Результат броска</h4>
                    <p class="text-sm text-gray-300 mt-1">${this.escapeHtml(roll.message)}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="text-xs px-2 py-1 ${successColor} rounded">${this.escapeHtml(roll.level)}</span>
                        ${roll.difficulty ? `<span class="text-xs bg-gray-600 px-2 py-1 rounded">Сложность: ${roll.difficulty}</span>` : ''}
                    </div>
                </div>
                <button onclick="this.parentElement.parentElement.remove()" class="text-gray-400 hover:text-white">
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        `;

        document.body.appendChild(notification);
        setTimeout(() => notification.remove(), 5000);
    }

    escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const el = document.querySelector('[data-room-id]');

    // Если на странице нет комнаты (логин, профиль, список комнат и т.д.) —
    // room.js просто ничего не делает: ни Echo, ни Pusher, ни WS-соединения.
    if (!el) return;

    const room = new DnDRoom(el.dataset.roomId, el.dataset.userId);

    // Закрываем WS-соединение при уходе со страницы — не держим сокет висящим
    // после перехода на другую страницу (пока это классический MPA-переход,
    // но на всякий случай, если позже добавится SPA-навигация без reload).
    window.addEventListener('pagehide', () => room.disconnect());
});
