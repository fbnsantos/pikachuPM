// prototypes.js - Atualizado com filtros e responsáveis
let currentPrototype = null;
let currentStory = null;
let prototypes = [];
let stories = [];
let users = []; // Lista de usuários
let currentUser = 'test'; // Pode ser obtido de uma sessão PHP

// Caminho da API
const API_PATH = window.PROTOTYPES_API_PATH || 'prototypes_api.php';

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    console.log('Prototypes JS loaded. API Path:', API_PATH);
    loadUsers(); // Carregar usuários primeiro
    loadPrototypes();
    
    // Event listeners
    const searchInput = document.getElementById('searchInput');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            applyFilters();
        });
    }
    
    // Filtros
    const filterAlphabetical = document.getElementById('filterAlphabetical');
    if (filterAlphabetical) {
        filterAlphabetical.addEventListener('change', applyFilters);
    }
    
    const filterMyResponsible = document.getElementById('filterMyResponsible');
    if (filterMyResponsible) {
        filterMyResponsible.addEventListener('change', applyFilters);
    }
    
    // Botões
    const btnNewPrototype = document.getElementById('btnNewPrototype');
    if (btnNewPrototype) {
        btnNewPrototype.addEventListener('click', createNewPrototype);
    }
    
    const btnClosePrototypeModal = document.getElementById('btnClosePrototypeModal');
    if (btnClosePrototypeModal) {
        btnClosePrototypeModal.addEventListener('click', closePrototypeModal);
    }
    
    const btnSavePrototype = document.getElementById('btnSavePrototype');
    if (btnSavePrototype) {
        btnSavePrototype.addEventListener('click', savePrototype);
    }
    
    const btnCancelPrototype = document.getElementById('btnCancelPrototype');
    if (btnCancelPrototype) {
        btnCancelPrototype.addEventListener('click', closePrototypeModal);
    }
    
    const btnCloseStoryModal = document.getElementById('btnCloseStoryModal');
    if (btnCloseStoryModal) {
        btnCloseStoryModal.addEventListener('click', closeStoryModal);
    }
    
    const btnSaveStory = document.getElementById('btnSaveStory');
    if (btnSaveStory) {
        btnSaveStory.addEventListener('click', saveStory);
    }
    
    const btnCancelStory = document.getElementById('btnCancelStory');
    if (btnCancelStory) {
        btnCancelStory.addEventListener('click', closeStoryModal);
    }
});

// ===== CARREGAR USUÁRIOS =====
async function loadUsers() {
    try {
        const response = await fetch(`${API_PATH}?action=get_users`);
        users = await response.json();
        console.log('Users loaded:', users);
    } catch (error) {
        console.error('Error loading users:', error);
        users = [];
    }
}

// ===== FILTROS =====
function applyFilters() {
    const searchTerm = document.getElementById('searchInput').value.toLowerCase();
    const alphabetical = document.getElementById('filterAlphabetical').checked;
    const myResponsible = document.getElementById('filterMyResponsible').checked;
    
    let filtered = [...prototypes];
    
    // Filtro de busca
    if (searchTerm) {
        filtered = filtered.filter(p => 
            (p.name && p.name.toLowerCase().includes(searchTerm)) ||
            (p.description && p.description.toLowerCase().includes(searchTerm)) ||
            (p.identifier && p.identifier.toLowerCase().includes(searchTerm))
        );
    }
    
    // Filtro "apenas meus"
    if (myResponsible) {
        filtered = filtered.filter(p => 
            p.responsible && p.responsible.toLowerCase() === currentUser.toLowerCase()
        );
    }
    
    // Ordenação alfabética
    if (alphabetical) {
        filtered.sort((a, b) => {
            const nameA = (a.name || '').toLowerCase();
            const nameB = (b.name || '').toLowerCase();
            return nameA.localeCompare(nameB);
        });
    } else {
        // Ordenar por ID (ordem de criação) quando não alfabético
        filtered.sort((a, b) => b.id - a.id);
    }
    
    renderPrototypesList(filtered);
}

function renderPrototypesList(list) {
    const listEl = document.getElementById('prototypesList');
    
    if (list.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <h3>No prototypes found</h3>
                <p>Ajuste os filtros ou crie um novo protótipo</p>
            </div>
        `;
        return;
    }
    
    listEl.innerHTML = list.map(p => {
        const responsibleBadge = p.responsible 
            ? `<span class="prototype-responsible">👤 ${escapeHtml(p.responsible)}</span>` 
            : '';
        
        const participantsCount = p.participants && p.participants.length > 0
            ? `<span style="font-size: 11px;">👥 ${p.participants.length} participantes</span>`
            : '';
            
        return `
            <div class="prototype-item ${currentPrototype?.id === p.id ? 'active' : ''}" 
                 onclick="selectPrototype(${p.id})">
                <div class="prototype-name">${escapeHtml(p.name)}</div>
                <div class="prototype-meta">
                    ${p.identifier ? `<span>${escapeHtml(p.identifier)}</span>` : ''}
                    ${participantsCount}
                </div>
                ${responsibleBadge}
            </div>
        `;
    }).join('');
}

// ===== PROTOTYPES =====
async function loadPrototypes(search = '') {
    try {
        const url = `${API_PATH}?action=get_prototypes${search ? `&search=${encodeURIComponent(search)}` : ''}`;
        console.log('Loading prototypes from:', url);
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        prototypes = await response.json();
        console.log('Prototypes loaded:', prototypes);
        
        // Garantir que todos tenham arrays de participantes
        prototypes = prototypes.map(p => ({
            ...p,
            participants: p.participants || [],
            responsible: p.responsible || ''
        }));
        
        applyFilters();
        
    } catch (error) {
        console.error('Error loading prototypes:', error);
        alert('Failed to load prototypes: ' + error.message);
    }
}

function createNewPrototype() {
    currentPrototype = null;
    document.getElementById('prototypeModalTitle').textContent = 'New Prototype';
    document.getElementById('prototypeName').value = '';
    document.getElementById('prototypeIdentifier').value = '';
    document.getElementById('prototypeDescription').value = '';
    
    // Preencher select de responsável
    populateUserSelects();
    document.getElementById('prototypeResponsible').value = '';
    
    // Limpar seleção de participantes
    const participantsSelect = document.getElementById('prototypeParticipants');
    Array.from(participantsSelect.options).forEach(opt => opt.selected = false);
    
    document.getElementById('prototypeModal').classList.add('active');
}

function populateUserSelects() {
    // Preencher responsável
    const responsibleSelect = document.getElementById('prototypeResponsible');
    responsibleSelect.innerHTML = '<option value="">Selecione um responsável...</option>';
    
    users.forEach(user => {
        const option = document.createElement('option');
        option.value = user.username;
        option.textContent = `${user.username} (${user.email || 'sem email'})`;
        responsibleSelect.appendChild(option);
    });
    
    // Preencher participantes
    const participantsSelect = document.getElementById('prototypeParticipants');
    participantsSelect.innerHTML = '';
    
    users.forEach(user => {
        const option = document.createElement('option');
        option.value = user.username;
        option.textContent = `${user.username} (${user.email || 'sem email'})`;
        participantsSelect.appendChild(option);
    });
}

function closePrototypeModal() {
    document.getElementById('prototypeModal').classList.remove('active');
}

async function savePrototype() {
    const name = document.getElementById('prototypeName').value.trim();
    const identifier = document.getElementById('prototypeIdentifier').value.trim();
    const description = document.getElementById('prototypeDescription').value.trim();
    const responsible = document.getElementById('prototypeResponsible').value;
    
    // Obter participantes selecionados
    const participantsSelect = document.getElementById('prototypeParticipants');
    const selectedParticipants = Array.from(participantsSelect.selectedOptions)
        .map(opt => opt.value)
        .filter(v => v);
    
    if (!name) {
        alert('Please enter a prototype name');
        return;
    }
    
    try {
        const data = {
            name,
            identifier,
            description,
            responsible,
            participants: JSON.stringify(selectedParticipants)
        };
        
        if (currentPrototype) {
            data.id = currentPrototype.id;
        }
        
        const formData = new FormData();
        formData.append('action', currentPrototype ? 'update_prototype' : 'create_prototype');
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            closePrototypeModal();
            await loadPrototypes();
            if (result.id) {
                selectPrototype(result.id);
            } else if (currentPrototype) {
                selectPrototype(currentPrototype.id);
            }
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error saving prototype:', error);
        alert('Failed to save prototype');
    }
}

async function selectPrototype(id) {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        // Garantir arrays
        currentPrototype.participants = currentPrototype.participants || [];
        currentPrototype.responsible = currentPrototype.responsible || '';
        
        renderPrototypeDetail();
        await loadUserStories(id);
        
        // Atualizar lista visual - encontrar o item correto
        applyFilters(); // Isto vai re-renderizar a lista com o item ativo
    } catch (error) {
        console.error('Error loading prototype:', error);
        alert('Failed to load prototype details');
    }
}

function renderPrototypeDetail() {
    const panel = document.getElementById('detailPanel');
    
    const participants = currentPrototype.participants || [];
    const participantsHtml = participants.length > 0 
        ? participants.map(p => `
            <div class="participant-item">
                <div class="participant-info">
                    <span>👤 ${escapeHtml(p)}</span>
                </div>
            </div>
        `).join('')
        : '<p class="text-muted">Nenhum participante</p>';
    
    panel.innerHTML = `
        <div class="detail-header">
            <div>
                <h1 class="detail-title">${escapeHtml(currentPrototype.name)}</h1>
                ${currentPrototype.identifier ? `<p style="color: #64748b; font-size: 14px;">ID: ${escapeHtml(currentPrototype.identifier)}</p>` : ''}
            </div>
            <div class="detail-actions">
                <button class="btn btn-primary" onclick="editPrototype()">✏️ Edit</button>
                <button class="btn btn-danger" onclick="deletePrototype()">🗑️ Delete</button>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="section">
            <div class="section-header">
                <h3>📋 Basic Information</h3>
                <button class="edit-btn" onclick="editBasicInfo()" title="Editar">✏️</button>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Description</div>
                    <div class="info-value">${escapeHtml(currentPrototype.description || 'No description')}</div>
                </div>
                <div class="info-item">
                    <div class="info-label">Responsável</div>
                    <div class="info-value">
                        ${currentPrototype.responsible 
                            ? `<span class="participant-badge">👤 ${escapeHtml(currentPrototype.responsible)}</span>` 
                            : '<span class="text-muted">Não definido</span>'}
                    </div>
                </div>
            </div>
        </div>

        <!-- Participants -->
        <div class="section">
            <div class="section-header">
                <h3>👥 Participantes</h3>
                <button class="edit-btn" onclick="editParticipants()" title="Editar">✏️</button>
            </div>
            <div class="participants-list">
                ${participantsHtml}
            </div>
        </div>

        <!-- User Stories -->
        <div class="section">
            <div class="section-header">
                <h3>📝 User Stories</h3>
                <button class="btn btn-primary btn-small" onclick="createNewStory()">+ Add Story</button>
            </div>
            <div class="stories-list" id="storiesList">
                <p class="text-muted">No stories yet</p>
            </div>
        </div>
    `;
}

function editBasicInfo() {
    editPrototype();
}

function editParticipants() {
    editPrototype();
}

function editPrototype() {
    document.getElementById('prototypeModalTitle').textContent = 'Edit Prototype';
    document.getElementById('prototypeName').value = currentPrototype.name;
    document.getElementById('prototypeIdentifier').value = currentPrototype.identifier || '';
    document.getElementById('prototypeDescription').value = currentPrototype.description || '';
    
    // Preencher selects
    populateUserSelects();
    
    // Selecionar responsável
    document.getElementById('prototypeResponsible').value = currentPrototype.responsible || '';
    
    // Selecionar participantes
    const participantsSelect = document.getElementById('prototypeParticipants');
    const currentParticipants = currentPrototype.participants || [];
    
    Array.from(participantsSelect.options).forEach(opt => {
        opt.selected = currentParticipants.includes(opt.value);
    });
    
    document.getElementById('prototypeModal').classList.add('active');
}

async function deletePrototype() {
    if (!confirm('Are you sure you want to delete this prototype? This action cannot be undone.')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_prototype');
        formData.append('id', currentPrototype.id);
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            currentPrototype = null;
            document.getElementById('detailPanel').innerHTML = `
                <div class="empty-state">
                    <h3>Select a prototype</h3>
                    <p>Choose a prototype from the list to view details</p>
                </div>
            `;
            await loadPrototypes();
        } else {
            alert('Error deleting prototype: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error deleting prototype:', error);
        alert('Failed to delete prototype');
    }
}

// ===== USER STORIES =====
async function loadUserStories(prototypeId) {
    try {
        const response = await fetch(`${API_PATH}?action=get_stories&prototype_id=${prototypeId}`);
        stories = await response.json();
        renderUserStories();
    } catch (error) {
        console.error('Error loading stories:', error);
    }
}

function renderUserStories() {
    const container = document.getElementById('storiesList');
    
    if (stories.length === 0) {
        container.innerHTML = '<p class="text-muted">No stories yet</p>';
        return;
    }
    
    container.innerHTML = stories.map(story => `
        <div class="story-item">
            <div class="story-header">
                <span class="badge badge-info">${escapeHtml(story.priority)}</span>
                <div class="story-actions">
                    <button class="btn btn-small btn-secondary" onclick="editStory(${story.id})">✏️</button>
                    <button class="btn btn-small btn-danger" onclick="deleteStory(${story.id})">🗑️</button>
                </div>
            </div>
            <p>${escapeHtml(story.story_text)}</p>
        </div>
    `).join('');
}

function createNewStory() {
    currentStory = null;
    document.getElementById('storyModalTitle').textContent = 'New User Story';
    document.getElementById('storyText').value = '';
    document.getElementById('storyPriority').value = 'Should';
    document.getElementById('storyModal').classList.add('active');
}

function closeStoryModal() {
    document.getElementById('storyModal').classList.remove('active');
}

async function editStory(id) {
    currentStory = stories.find(s => s.id === id);
    if (!currentStory) return;
    
    document.getElementById('storyModalTitle').textContent = 'Edit User Story';
    document.getElementById('storyText').value = currentStory.story_text;
    document.getElementById('storyPriority').value = currentStory.priority;
    document.getElementById('storyModal').classList.add('active');
}

async function saveStory() {
    const text = document.getElementById('storyText').value.trim();
    const priority = document.getElementById('storyPriority').value;
    
    if (!text) {
        alert('Please enter story text');
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', currentStory ? 'update_story' : 'create_story');
        formData.append('prototype_id', currentPrototype.id);
        formData.append('story_text', text);
        formData.append('priority', priority);
        
        if (currentStory) {
            formData.append('id', currentStory.id);
        }
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeStoryModal();
            await loadUserStories(currentPrototype.id);
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error saving story:', error);
        alert('Failed to save story');
    }
}

async function deleteStory(id) {
    if (!confirm('Delete this user story?')) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_story');
        formData.append('id', id);
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            await loadUserStories(currentPrototype.id);
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error deleting story:', error);
        alert('Failed to delete story');
    }
}

// ===== UTILITIES =====
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}