// prototypes.js - VERSÃO SIMPLES com apenas ordenação alfabética
let currentPrototype = null;
let currentStory = null;
let prototypes = [];
let stories = [];

const API_PATH = window.PROTOTYPES_API_PATH || 'prototypes_api.php';

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    console.log('Prototypes JS loaded');
    loadPrototypes();
    
    document.getElementById('searchInput').addEventListener('input', (e) => {
        loadPrototypes(e.target.value);
    });
    
    // Event listener para ordenação alfabética
    const sortCheck = document.getElementById('sortAlphabetical');
    if (sortCheck) {
        sortCheck.addEventListener('change', () => {
            renderPrototypesList();
        });
    }
});

// ===== PROTOTYPES =====
async function loadPrototypes(search = '') {
    try {
        const url = `${API_PATH}?action=get_prototypes${search ? `&search=${encodeURIComponent(search)}` : ''}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        prototypes = await response.json();
        console.log('Prototypes loaded:', prototypes.length);
        
        renderPrototypesList();
        
    } catch (error) {
        console.error('Error loading prototypes:', error);
        alert('Failed to load prototypes: ' + error.message);
    }
}

function renderPrototypesList() {
    const listEl = document.getElementById('prototypesList');
    
    if (prototypes.length === 0) {
        listEl.innerHTML = `
            <div class="empty-state">
                <h3>No prototypes yet</h3>
                <p>Create your first prototype to get started</p>
            </div>
        `;
        return;
    }
    
    // Criar cópia para ordenar
    let displayPrototypes = [...prototypes];
    
    // Verificar se deve ordenar alfabeticamente
    const sortCheck = document.getElementById('sortAlphabetical');
    if (sortCheck && sortCheck.checked) {
        displayPrototypes.sort((a, b) => {
            const nameA = (a.short_name || a.title || a.name || '').toLowerCase();
            const nameB = (b.short_name || b.title || b.name || '').toLowerCase();
            return nameA.localeCompare(nameB);
        });
    }
    
    listEl.innerHTML = displayPrototypes.map(p => {
        const displayName = p.short_name || p.title || p.name || 'Unnamed';
        
        return `
            <div class="prototype-item ${currentPrototype?.id === p.id ? 'active' : ''}" 
                 onclick="selectPrototype(${p.id})">
                <div class="prototype-name">${escapeHtml(displayName)}</div>
            </div>
        `;
    }).join('');
}

function createNewPrototype() {
    alert('Função criar protótipo - a implementar');
}

async function selectPrototype(id) {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        renderPrototypeDetail();
        await loadUserStories(id);
        
        // Atualizar visual
        renderPrototypesList();
        
    } catch (error) {
        console.error('Error loading prototype:', error);
        alert('Failed to load prototype details');
    }
}

function renderPrototypeDetail() {
    const panel = document.getElementById('detailPanel');
    
    const displayName = currentPrototype.short_name || currentPrototype.title || currentPrototype.name || 'Unnamed';
    
    panel.innerHTML = `
        <div class="detail-header">
            <div>
                <h1 class="detail-title">${escapeHtml(displayName)}</h1>
            </div>
            <div class="detail-actions">
                <button class="btn btn-danger" onclick="deletePrototype()">🗑️ Delete</button>
            </div>
        </div>

        <!-- Basic Information -->
        <div class="section">
            <h3>📋 Basic Information</h3>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Description</div>
                    <div class="info-value">${escapeHtml(currentPrototype.vision || currentPrototype.description || 'No description')}</div>
                </div>
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

async function deletePrototype() {
    if (!confirm('Are you sure you want to delete this prototype?')) {
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
            alert('Error deleting prototype');
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
                <span class="badge badge-info">${escapeHtml(story.moscow_priority || story.priority || 'Should')}</span>
                <div class="story-actions">
                    <button class="btn btn-small btn-danger" onclick="deleteStory(${story.id})">🗑️</button>
                </div>
            </div>
            <p>${escapeHtml(story.story_text)}</p>
        </div>
    `).join('');
}

function createNewStory() {
    document.getElementById('storyModalTitle').textContent = 'New User Story';
    document.getElementById('storyText').value = '';
    document.getElementById('storyPriority').value = 'Should';
    document.getElementById('storyModal').classList.add('active');
}

function closeStoryModal() {
    document.getElementById('storyModal').classList.remove('active');
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
        formData.append('action', 'create_story');
        formData.append('prototype_id', currentPrototype.id);
        formData.append('story_text', text);
        formData.append('priority', priority);
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        
        if (result.success) {
            closeStoryModal();
            await loadUserStories(currentPrototype.id);
        } else {
            alert('Error saving story');
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
            alert('Error deleting story');
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