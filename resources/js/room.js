class DnDRoom {
    constructor(roomId, userId) {
        console.log('DnDRoom initialized', { roomId, userId });
        
        this.roomId = roomId;
        this.userId = userId;
        this.lastGameMessage = 0;
        this.lastOocMessage = 0;
        this.lastGameTimestamp = 0;
        this.lastOocTimestamp = 0;
        this.pollingInterval = null;
        this.isPolling = false;
        this.csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
        
        // Получаем начальные timestamp
        this.initTimestamps();
        this.init();
    }
    
    initTimestamps() {
        const gameChat = document.getElementById('game-chat');
        const oocChat = document.getElementById('ooc-chat');
        
        if (gameChat && gameChat.lastElementChild) {
            const lastMsg = gameChat.lastElementChild;
            this.lastGameTimestamp = lastMsg.dataset.timestamp || 0;
            this.lastGameMessage = lastMsg.dataset.messageId || 0;
        }
        
        if (oocChat && oocChat.lastElementChild) {
            const lastMsg = oocChat.lastElementChild;
            this.lastOocTimestamp = lastMsg.dataset.timestamp || 0;
            this.lastOocMessage = lastMsg.dataset.messageId || 0;
        }
    }
    
    init() {
        console.log('Initializing room...');
        this.initGameChat();
        this.initOocChat();
        this.initDiceRoll();
        this.startPolling();
        this.scrollToBottom('game-chat');
        this.scrollToBottom('ooc-chat');
    }
    
    initGameChat() {
        const form = document.getElementById('game-message-form');
        if (!form) {
            console.log('Game chat form not found');
            return;
        }
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const input = document.getElementById('game-message-input');
            const message = input.value.trim();
            
            if (!message) return;
            
            input.disabled = true;
            
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
                    console.log('Message sent:', data);
                } else {
                    this.showError(data.error || 'Ошибка при отправке сообщения');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showError('Ошибка соединения');
            } finally {
                input.disabled = false;
                input.focus();
            }
        });
    }
    
    initOocChat() {
        const form = document.getElementById('ooc-message-form');
        if (!form) return;
        
        form.addEventListener('submit', async (e) => {
            e.preventDefault();
            
            const input = document.getElementById('ooc-message-input');
            const message = input.value.trim();
            
            if (!message) return;
            
            input.disabled = true;
            
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
                
                if (response.ok) {
                    input.value = '';
                } else {
                    this.showError('Ошибка при отправке сообщения');
                }
            } catch (error) {
                console.error('Error:', error);
                this.showError('Ошибка соединения');
            } finally {
                input.disabled = false;
                input.focus();
            }
        });
    }
    
    initDiceRoll() {
        const rollBtn = document.getElementById('roll-dice');
        if (!rollBtn) return;
        
        rollBtn.addEventListener('click', () => {
            const difficulty = prompt('Введите сложность (или оставьте пустым для обычного броска):', '');
            
            let message = '/roll';
            if (difficulty && !isNaN(difficulty) && difficulty > 0) {
                message += ' ' + difficulty;
            }
            
            const input = document.getElementById('game-message-input');
            input.value = message;
            document.getElementById('game-message-form').dispatchEvent(new Event('submit'));
        });
    }
    
    startPolling() {
        if (this.pollingInterval) clearInterval(this.pollingInterval);
        this.pollingInterval = setInterval(() => this.pollMessages(), 2000);
        console.log('Polling started');
    }
    
    async pollMessages() {
        if (this.isPolling) return;
        this.isPolling = true;
        
        try {
            await Promise.all([
                this.pollGameMessages(),
                this.pollOocMessages(),
                this.pollRoomStatus(),
                this.pollPlayersList()
            ]);
        } catch (error) {
            console.error('Polling error:', error);
        } finally {
            this.isPolling = false;
        }
    }
    
    async pollRoomStatus() {
        try {
            const response = await fetch(`/rooms/${this.roomId}/status`);
            if (response.ok) {
                const data = await response.json();
                
                // Обновляем статус на странице
                const statusElement = document.querySelector('.status-badge');
                if (statusElement) {
                    const statusText = data.status === 'waiting' ? 'Ожидание' : (data.status === 'playing' ? 'В игре' : 'Завершена');
                    const statusClass = data.status === 'waiting' ? 'bg-yellow-600' : (data.status === 'playing' ? 'bg-green-600' : 'bg-gray-600');
                    
                    statusElement.textContent = statusText;
                    statusElement.className = `px-2 py-1 rounded text-xs ${statusClass}`;
                }
                
                // Если статус изменился на playing, активируем поля ввода
                if (data.status === 'playing') {
                    const gameInput = document.getElementById('game-message-input');
                    const gameSubmit = document.querySelector('#game-message-form button[type="submit"]');
                    const rollBtn = document.getElementById('roll-dice');
                    
                    if (gameInput) gameInput.disabled = false;
                    if (gameSubmit) gameSubmit.disabled = false;
                    if (rollBtn) rollBtn.disabled = false;
                }
            }
        } catch (error) {
            console.error('Error checking room status:', error);
        }
    }
    
    async pollPlayersList() {
        try {
            const response = await fetch(`/rooms/${this.roomId}/status`);
            if (response.ok) {
                const data = await response.json();
                
                // Обновляем список игроков
                const playersContainer = document.querySelector('.players-container');
                if (playersContainer && data.users) {
                    this.updatePlayersList(playersContainer, data.users);
                }
                
                // Удаляем этот блок, так как обновление счетчика теперь в updatePlayersList
                // const playersCount = document.querySelector('.players-count');
                // if (playersCount) {
                //     playersCount.textContent = `${data.users_count}/${data.users_count + (data.users_count < 4 ? '?' : '4')}`;
                // }
            }
        } catch (error) {
            console.error('Error updating players:', error);
        }
    }
    
    updatePlayersList(container, users) {
        // Сохраняем текущие data-user-id для проверки изменений
        const currentIds = new Set();
        container.querySelectorAll('[data-user-id]').forEach(el => {
            currentIds.add(el.dataset.userId);
        });
        
        // Проверяем, есть ли новые игроки
        let hasChanges = false;
        users.forEach(user => {
            if (!currentIds.has(String(user.id))) {
                hasChanges = true;
            }
        });
        
        if (!hasChanges && users.length === currentIds.size) return;
        
        // Обновляем заголовок с участниками
        const headerElement = document.querySelector('.players-header');
        if (headerElement) {
            const maxPlayers = headerElement.dataset.max || '2';
            headerElement.innerHTML = `Участники <span class="players-count">${users.length}</span>/${maxPlayers}`;
        }
        
        // Очищаем и перестраиваем список
        container.innerHTML = '';
        
        if (users.length === 0) {
            container.innerHTML = '<div class="text-center text-gray-500 py-4">Нет участников</div>';
            return;
        }
        
        users.forEach(user => {
            const readyClass = user.is_ready ? 'bg-green-900 bg-opacity-20' : 'bg-gray-700';
            const readyIcon = user.is_ready ? '<span class="ml-1 text-xs text-green-400">✅</span>' : '';
            
            // Получаем русское название класса
            let className = '';
            if (user.character_class) {
                const classMap = {
                    'fighter': 'Воин',
                    'wizard': 'Волшебник',
                    'rogue': 'Плут',
                    'cleric': 'Жрец',
                    'ranger': 'Следопыт',
                    'paladin': 'Паладин',
                    'bard': 'Бард',
                    'barbarian': 'Варвар'
                };
                className = classMap[user.character_class] || user.character_class;
            }
            
            const stats = user.character_name ? 
                `<div class="text-xs text-gray-400 truncate">
                    ${className ? '<span class="text-purple-400">' + className + '</span> | ' : ''}
                    HP: ${user.current_hp}/${user.max_hp} | AC: ${user.armor_class}
                </div>` : '';
            
            const div = document.createElement('div');
            div.className = `flex items-center justify-between p-2 ${readyClass} rounded-lg`;
            div.dataset.userId = user.id;
            
            div.innerHTML = `
                <div class="flex items-center space-x-2 min-w-0 flex-1">
                    <div class="w-6 h-6 sm:w-8 sm:h-8 rounded-full bg-gray-600 flex items-center justify-center text-xs sm:text-sm font-bold flex-shrink-0">
                        ${(user.character_name || user.name).charAt(0)}
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="font-medium text-xs sm:text-sm truncate">
                            ${user.character_name || user.name} ${readyIcon}
                        </div>
                        ${stats}
                    </div>
                </div>
            `;
            
            container.appendChild(div);
        });
    }
    
    async pollGameMessages() {
        const response = await fetch(`/rooms/${this.roomId}/game-messages?after=${this.lastGameTimestamp}`);
        if (!response.ok) return;
        
        const messages = await response.json();
        if (messages.length > 0) {
            console.log('New game messages:', messages);
        }
        
        messages.forEach(msg => {
            this.addGameMessage(msg);
            this.lastGameTimestamp = Math.max(this.lastGameTimestamp, msg.created_at);
            this.lastGameMessage = Math.max(this.lastGameMessage, msg.id);
        });
    }
    
    async pollOocMessages() {
        const response = await fetch(`/rooms/${this.roomId}/ooc-messages?after=${this.lastOocTimestamp}`);
        if (!response.ok) return;
        
        const messages = await response.json();
        messages.forEach(msg => {
            this.addOocMessage(msg);
            this.lastOocTimestamp = Math.max(this.lastOocTimestamp, msg.created_at);
            this.lastOocMessage = Math.max(this.lastOocMessage, msg.id);
        });
    }
    
    addGameMessage(msg) {
        const chat = document.getElementById('game-chat');
        if (!chat) return;
        
        // Удаляем сообщение "Пока нет сообщений"
        const emptyMessage = document.getElementById('game-empty-message');
        if (emptyMessage) {
            emptyMessage.remove();
        }
        
        // Проверяем дубликаты
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
                    <p class="text-sm text-gray-300 mt-1">${roll.message}</p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="text-xs px-2 py-1 ${successColor} rounded">${roll.level}</span>
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
    if (el) {
        new DnDRoom(el.dataset.roomId, el.dataset.userId);
    }
});