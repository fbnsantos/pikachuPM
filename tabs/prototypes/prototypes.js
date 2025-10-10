// prototypes.js
let currentPrototype = null;
let currentStory = null;
let prototypes = [];
let stories = [];

// Caminho da API (definido no HTML ou usar padrão)
const API_PATH = window.PROTOTYPES_API_PATH || 'prototypes_api.php';

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    console.log('Prototypes JS loaded. API Path:', API_PATH);
    loadPrototypes();
    
    document.getElementById('searchInput').addEventListener('input', (e) => {
        loadPrototypes(e.target.value);
    });
});

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
        
        const listEl = document.getElementById('prototypesList');
        
        if (prototypes.length === 0) {
            listEl.innerHTML = `
                <div class="empty-state">
                    <h3>No prototypes found</h3>
                    <p>Create your first prototype to get started</p>
                </div>
            `;
            return;
        }
        
        listEl.innerHTML = prototypes.map(p => `
            <div class="prototype-item ${currentPrototype?.id === p.id ? 'active' : ''}" 
                 onclick="selectPrototype(${p.id})">
                <h3>${escapeHtml(p.short_name)}</h3>
                <p>${escapeHtml(p.title)}</p>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error loading prototypes:', error);
    }
}

async function selectPrototype(id) {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        console.log('Prototype selected:', currentPrototype);
        
        renderPrototypeDetail();
        loadStories();
        loadParticipants(); // ⬅️ ADICIONAR ESTA LINHA
        
        // Update active state
        document.querySelectorAll('.prototype-item').forEach(item => {
            item.classList.remove('active');
        });
        event.currentTarget?.classList.add('active');
    } catch (error) {
        console.error('Error loading prototype:', error);
    }
}

// Adicionar ao prototypes.js - substituir a função renderPrototypeDetail()

function renderPrototypeDetail() {
    const panel = document.getElementById('detailPanel');
    
    panel.innerHTML = `
        <div class="detail-section">
            <div class="section-header">
                <h3>📋 Basic Information</h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Short Name</div>
                    <div class="info-value" id="view-shortName">${escapeHtml(currentPrototype.short_name || 'Not defined')}</div>
                    <button class="edit-btn" onclick="editField('shortName', 'text')" title="Edit">✏️</button>
                </div>
                <div class="info-item">
                    <div class="info-label">Title</div>
                    <div class="info-value" id="view-title">${escapeHtml(currentPrototype.title || 'Not defined')}</div>
                    <button class="edit-btn" onclick="editField('title', 'text')" title="Edit">✏️</button>
                </div>
            </div>
            
            <!-- NOVA SECÇÃO: Team Participants -->
            <div style="margin-top: 30px;">
                <div class="section-header">
                    <h4 style="font-size: 16px; color: #4a5568; margin: 0;">👥 Team Participants</h4>
                    <button class="btn btn-primary btn-small" onclick="openParticipantModal()">+ Add Participant</button>
                </div>
                <div id="participantsTable" style="margin-top: 15px;"></div>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-header">
                <h3>🎯 Product Vision Board</h3>
            </div>
            <div class="vision-grid">
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Vision</h4>
                        <button class="edit-btn" onclick="editField('vision', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-vision">${escapeHtml(currentPrototype.vision || 'Not defined')}</div>
                </div>
                
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Product Statement</h4>
                        <button class="edit-btn" onclick="editField('sentence', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-sentence">${escapeHtml(currentPrototype.sentence || 'Not defined')}</div>
                </div>
                
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Target Group</h4>
                        <button class="edit-btn" onclick="editField('target_group', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-target_group">${escapeHtml(currentPrototype.target_group || 'Not defined')}</div>
                </div>
                
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Needs</h4>
                        <button class="edit-btn" onclick="editField('needs', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-needs">${escapeHtml(currentPrototype.needs || 'Not defined')}</div>
                </div>
                
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Product Description</h4>
                        <button class="edit-btn" onclick="editField('product_description', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-product_description">${escapeHtml(currentPrototype.product_description || 'Not defined')}</div>
                </div>
                
                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Business Goals</h4>
                        <button class="edit-btn" onclick="editField('business_goals', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-business_goals">${escapeHtml(currentPrototype.business_goals || 'Not defined')}</div>
                </div>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-header">
                <h3>🔗 Links & Resources</h3>
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <div class="info-label">Repository Links</div>
                    <div class="info-value" id="view-repo_links">${formatTextWithLinks(currentPrototype.repo_links || 'Not defined')}</div>
                    <button class="edit-btn" onclick="editField('repo_links', 'textarea')" title="Edit">✏️</button>
                </div>
                <div class="info-item">
                    <div class="info-label">Documentation Links</div>
                    <div class="info-value" id="view-documentation_links">${formatTextWithLinks(currentPrototype.documentation_links || 'Not defined')}</div>
                    <button class="edit-btn" onclick="editField('documentation_links', 'textarea')" title="Edit">✏️</button>
                </div>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-header">
                <h3>📝 User Stories</h3>
                <div style="display: flex; gap: 10px;">
                    <select id="priorityFilter" onchange="loadStories()" style="padding: 8px; border: 1px solid #e1e8ed; border-radius: 6px;">
                        <option value="">All Priorities</option>
                        <option value="Must">Must Have</option>
                        <option value="Should">Should Have</option>
                        <option value="Could">Could Have</option>
                        <option value="Won't">Won't Have</option>
                    </select>
                    <button class="btn btn-primary" onclick="openStoryModal()">+ Add Story</button>
                </div>
            </div>
            <div id="storiesList"></div>
        </div>

        <div class="action-bar">
            <button class="btn btn-danger" onclick="deletePrototype()">🗑️ Delete Prototype</button>
            <button class="btn btn-secondary" onclick="exportMarkdown()">📥 Export Markdown</button>
        </div>
    `;
    
    // Carregar participantes após renderizar
    loadParticipants();
}

// Função para formatar texto com quebras de linha
function formatText(text) {
    if (!text) return '';
    return text.split('\n').map(line => {
        line = escapeHtml(line.trim());
        if (line.startsWith('-') || line.startsWith('•')) {
            return `<div class="list-item">${line}</div>`;
        }
        return line ? `<p>${line}</p>` : '';
    }).join('');
}

// Função para formatar e tornar links clicáveis
function formatLinks(linksText) {
    if (!linksText) return '';
    
    const lines = linksText.split('\n').filter(line => line.trim());
    if (lines.length === 0) return '';
    
    return lines.map(link => {
        link = link.trim();
        // Detectar URLs
        const urlMatch = link.match(/(https?:\/\/[^\s]+)/);
        if (urlMatch) {
            const url = urlMatch[1];
            const label = link.replace(url, '').trim() || url;
            return `
                <div class="link-item">
                    <span class="link-icon">🔗</span>
                    <a href="${url}" target="_blank" rel="noopener noreferrer">${escapeHtml(label)}</a>
                    <span class="external-icon">↗</span>
                </div>
            `;
        }
        return `<div class="link-item"><span class="link-icon">📄</span>${escapeHtml(link)}</div>`;
    }).join('');
}

// Função para editar campo
let editingField = null;

function editField(fieldName, inputType) {
    // Se já está editando, cancelar edição anterior
    if (editingField) {
        cancelEdit();
    }
    
    editingField = fieldName;
    const viewElement = document.getElementById(`view-${fieldName}`);
    const currentValue = getCurrentFieldValue(fieldName);
    
    let editHTML;
    if (inputType === 'textarea') {
        editHTML = `
            <div class="edit-container">
                <textarea class="edit-input" id="edit-${fieldName}" rows="6">${escapeHtml(currentValue || '')}</textarea>
                <div class="edit-actions">
                    <button class="btn btn-small btn-success" onclick="saveField('${fieldName}')">💾 Save</button>
                    <button class="btn btn-small btn-secondary" onclick="cancelEdit()">✖ Cancel</button>
                </div>
            </div>
        `;
    } else {
        editHTML = `
            <div class="edit-container">
                <input type="text" class="edit-input" id="edit-${fieldName}" value="${escapeHtml(currentValue || '')}">
                <div class="edit-actions">
                    <button class="btn btn-small btn-success" onclick="saveField('${fieldName}')">💾 Save</button>
                    <button class="btn btn-small btn-secondary" onclick="cancelEdit()">✖ Cancel</button>
                </div>
            </div>
        `;
    }
    
    viewElement.innerHTML = editHTML;
    document.getElementById(`edit-${fieldName}`).focus();
}

function getCurrentFieldValue(fieldName) {
    const fieldMap = {
        'shortName': 'short_name',
        'title': 'title',
        'vision': 'vision',
        'targetGroup': 'target_group',
        'needs': 'needs',
        'productDescription': 'product_description',
        'businessGoals': 'business_goals',
        'sentence': 'sentence',
        'repoLinks': 'repo_links',
        'documentationLinks': 'documentation_links'
    };
    
    return currentPrototype[fieldMap[fieldName]] || '';
}

async function saveField(fieldName) {
    const inputElement = document.getElementById(`edit-${fieldName}`);
    const newValue = inputElement.value;
    
    // Mapear nome do campo para nome da coluna no BD
    const fieldMap = {
        'shortName': 'short_name',
        'title': 'title',
        'vision': 'vision',
        'targetGroup': 'target_group',
        'needs': 'needs',
        'productDescription': 'product_description',
        'businessGoals': 'business_goals',
        'sentence': 'sentence',
        'repoLinks': 'repo_links',
        'documentationLinks': 'documentation_links'
    };
    
    // Atualizar objeto local
    currentPrototype[fieldMap[fieldName]] = newValue;
    
    // Salvar no servidor
    try {
        const response = await fetch(`${API_PATH}?action=update_prototype`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(currentPrototype)
        });
        
        const result = await response.json();
        if (result.success) {
            // Atualizar visualização
            editingField = null;
            renderPrototypeDetail();
            loadStories(); // Recarregar para manter as histórias
        } else {
            alert('Error saving: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error saving field:', error);
        alert('Error saving changes');
    }
}

function cancelEdit() {
    editingField = null;
    renderPrototypeDetail();
    loadStories(); // Recarregar para manter as histórias
}

async function updatePrototype() {
    if (!currentPrototype) return;
    
    const data = {
        id: currentPrototype.id,
        short_name: document.getElementById('shortName').value,
        title: document.getElementById('title').value,
        vision: document.getElementById('vision').value,
        target_group: document.getElementById('targetGroup').value,
        needs: document.getElementById('needs').value,
        product_description: document.getElementById('productDescription').value,
        business_goals: document.getElementById('businessGoals').value,
        sentence: document.getElementById('sentence').value,
        repo_links: document.getElementById('repoLinks').value,
        documentation_links: document.getElementById('documentationLinks').value
    };
    
    try {
        const response = await fetch(`${API_PATH}?action=update_prototype`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            currentPrototype = { ...currentPrototype, ...data };
            loadPrototypes();
        }
    } catch (error) {
        console.error('Error updating prototype:', error);
        alert('Error updating prototype');
    }
}

function createNewPrototype() {
    const shortName = prompt('Enter short name for new prototype:');
    if (!shortName) return;
    
    const title = prompt('Enter title:');
    if (!title) return;
    
    const data = {
        short_name: shortName,
        title: title,
        vision: '',
        target_group: '',
        needs: '',
        product_description: '',
        business_goals: '',
        sentence: '',
        repo_links: '',
        documentation_links: ''
    };
    
    console.log('Creating prototype:', data);
    console.log('API Path:', API_PATH);
    
    fetch(`${API_PATH}?action=create_prototype`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        return response.json();
    })
    .then(result => {
        console.log('Create result:', result);
        if (result.success) {
            loadPrototypes();
            selectPrototype(result.id);
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error creating prototype:', error);
        alert('Error creating prototype: ' + error.message + '\nCheck console for details');
    });
}

async function deletePrototype() {
    if (!currentPrototype) return;
    
    if (!confirm(`Are you sure you want to delete "${currentPrototype.short_name}"? This will also delete all associated user stories.`)) {
        return;
    }
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_prototype');
        formData.append('id', currentPrototype.id);
        
        const response = await fetch(`${API_PATH}`, {
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
            loadPrototypes();
        }
    } catch (error) {
        console.error('Error deleting prototype:', error);
        alert('Error deleting prototype');
    }
}

// ===== USER STORIES =====
async function loadStories() {
    if (!currentPrototype) return;
    
    const priority = document.getElementById('priorityFilter')?.value || '';
    
    try {
        const url = `${API_PATH}?action=get_stories&prototype_id=${currentPrototype.id}${priority ? `&priority=${priority}` : ''}`;
        const response = await fetch(url);
        stories = await response.json();
        
        const listEl = document.getElementById('storiesList');
        
        if (stories.length === 0) {
            listEl.innerHTML = `
                <div class="empty-state">
                    <h3>No user stories yet</h3>
                    <p>Add your first user story</p>
                </div>
            `;
            return;
        }
        
        listEl.innerHTML = stories.map(story => `
            <div class="story-item ${story.moscow_priority.toLowerCase()}">
                <div class="story-header">
                    <span class="story-priority priority-${story.moscow_priority.toLowerCase()}">${story.moscow_priority}</span>
                    <div class="story-actions">
                        <button class="btn btn-secondary btn-small" onclick="viewStoryTasks(${story.id})">📋 Tasks</button>
                        <button class="btn btn-secondary btn-small" onclick="editStory(${story.id})">✏️</button>
                        <button class="btn btn-danger btn-small" onclick="deleteStory(${story.id})">🗑️</button>
                    </div>
                </div>
                <div class="story-text">${escapeHtml(story.story_text)}</div>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error loading stories:', error);
    }
}

function openStoryModal(storyId = null) {
    currentStory = storyId ? stories.find(s => s.id === storyId) : null;
    
    document.getElementById('storyModalTitle').textContent = currentStory ? 'Edit User Story' : 'New User Story';
    document.getElementById('storyText').value = currentStory?.story_text || '';
    document.getElementById('storyPriority').value = currentStory?.moscow_priority || 'Should';
    
    document.getElementById('storyModal').classList.add('active');
}

function closeStoryModal() {
    document.getElementById('storyModal').classList.remove('active');
    currentStory = null;
}

function editStory(id) {
    openStoryModal(id);
}

async function saveStory() {
    const storyText = document.getElementById('storyText').value.trim();
    const priority = document.getElementById('storyPriority').value;
    
    if (!storyText) {
        alert('Please enter story text');
        return;
    }
    
    const data = {
        prototype_id: currentPrototype.id,
        story_text: storyText,
        moscow_priority: priority
    };
    
    try {
        const action = currentStory ? 'update_story' : 'create_story';
        if (currentStory) {
            data.id = currentStory.id;
        }
        
        const response = await fetch(`${API_PATH}?action=${action}`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            closeStoryModal();
            loadStories();
        }
    } catch (error) {
        console.error('Error saving story:', error);
        alert('Error saving story');
    }
}

async function deleteStory(id) {
    if (!confirm('Delete this user story?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_story');
        formData.append('id', id);
        
        const response = await fetch(`${API_PATH}`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            loadStories();
        }
    } catch (error) {
        console.error('Error deleting story:', error);
        alert('Error deleting story');
    }
}

// ===== TASKS =====
async function viewStoryTasks(storyId) {
    currentStory = stories.find(s => s.id === storyId);
    
    try {
        const response = await fetch(`${API_PATH}?action=get_story_tasks&story_id=${storyId}`);
        const tasks = await response.json();
        
        const tasksList = tasks.length > 0 ? tasks.map(task => `
            <div class="task-item">
                <div>
                    <strong>${escapeHtml(task.title || 'Task #' + task.id)}</strong>
                    <span class="badge badge-info">${escapeHtml(task.status || 'pending')}</span>
                </div>
                <button class="btn btn-danger btn-small" onclick="unlinkTask(${task.link_id})">Unlink</button>
            </div>
        `).join('') : '<p>No tasks linked to this story yet.</p>';
        
        const modal = document.getElementById('taskModal');
        modal.querySelector('.modal-content').innerHTML = `
            <div class="modal-header">
                <h3>Tasks for Story #${storyId}</h3>
                <button class="close-modal" onclick="closeTaskModal()">&times;</button>
            </div>
            <div class="task-list">
                ${tasksList}
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="openCreateTaskForm(${storyId})">+ Create New Task</button>
                <button class="btn btn-secondary" onclick="closeTaskModal()">Close</button>
            </div>
        `;
        modal.classList.add('active');
    } catch (error) {
        console.error('Error loading tasks:', error);
    }
}

function openCreateTaskForm(storyId) {
    const story = stories.find(s => s.id === storyId);
    
    if (!story) {
        alert('Error: Story not found');
        console.error('Story ID:', storyId, 'Available stories:', stories);
        return;
    }
    
    currentStory = story;
    console.log('Opening create task form for story:', currentStory);
    
    const modal = document.getElementById('taskModal');
    modal.querySelector('.modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Create Task from Story #${storyId}</h3>
            <button class="close-modal" onclick="closeTaskModal()">&times;</button>
        </div>
        <div class="form-group">
            <label>Task Title</label>
            <input type="text" id="taskTitle" placeholder="Task title">
        </div>
        <div class="form-group">
            <label>Description</label>
            <textarea id="taskDescription" placeholder="Task description"></textarea>
        </div>
        <div class="form-group">
            <label>Priority</label>
            <select id="taskPriority">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
            </select>
        </div>
        <div class="action-bar">
            <button class="btn btn-primary" onclick="createTaskFromStory()">Create Task</button>
            <button class="btn btn-secondary" onclick="viewStoryTasks(${storyId})">← Back</button>
        </div>
    `;
    modal.classList.add('active');
}

async function createTaskFromStory() {
    const title = document.getElementById('taskTitle').value.trim();
    const description = document.getElementById('taskDescription').value.trim();
    const priority = document.getElementById('taskPriority').value;
    
    if (!title) {
        alert('Please enter task title');
        return;
    }
    
    if (!currentStory || !currentStory.id) {
        alert('Error: No story selected');
        console.error('currentStory:', currentStory);
        return;
    }
    
    const data = {
        story_id: currentStory.id,
        title: title,
        description: description,
        priority: priority
    };
    
    console.log('Creating task from story:', data);
    
    try {
        const response = await fetch(`${API_PATH}?action=create_task_from_story`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        console.log('Response status:', response.status);
        
        if (!response.ok) {
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const result = await response.json();
        console.log('Create task result:', result);
        
        if (result.success) {
            alert('Task created successfully!');
            viewStoryTasks(currentStory.id);
        } else {
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('Error creating task:', error);
        alert('Error creating task: ' + error.message + '\nCheck console for details');
    }
}

async function unlinkTask(linkId) {
    if (!confirm('Unlink this task from the story?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'unlink_task');
        formData.append('id', linkId);
        
        const response = await fetch(`${API_PATH}`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            viewStoryTasks(currentStory.id);
        }
    } catch (error) {
        console.error('Error unlinking task:', error);
        alert('Error unlinking task');
    }
}

function closeTaskModal() {
    document.getElementById('taskModal').classList.remove('active');
}

// ===== EXPORT =====
function exportMarkdown() {
    if (!currentPrototype) return;
    window.location.href = `${API_PATH}?action=export_markdown&id=${currentPrototype.id}`;
}

// ===== UTILITY =====
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

// ===== PARTICIPANTS MANAGEMENT =====
let participants = [];

async function loadParticipants() {
    if (!currentPrototype) return;
    
    try {
        const response = await fetch(`${API_PATH}?action=get_participants&prototype_id=${currentPrototype.id}`);
        participants = await response.json();
        renderParticipantsTable();
    } catch (error) {
        console.error('Error loading participants:', error);
    }
}

function renderParticipantsTable() {
    const container = document.getElementById('participantsTable');
    if (!container) return;
    
    if (participants.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 20px; color: #64748b;">
                <p>No participants yet</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = `
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="background: #f9fafb; border-bottom: 2px solid #e5e7eb;">
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Username</th>
                    <th style="padding: 12px; text-align: left; font-weight: 600; color: #4a5568;">Role</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #4a5568;">Leader</th>
                    <th style="padding: 12px; text-align: center; font-weight: 600; color: #4a5568;">Actions</th>
                </tr>
            </thead>
            <tbody>
                ${participants.map(p => `
                    <tr style="border-bottom: 1px solid #e5e7eb;">
                        <td style="padding: 12px;">
                            <strong>${escapeHtml(p.username)}</strong>
                            ${p.is_leader ? '<span style="margin-left: 8px; background: #fef3c7; color: #92400e; padding: 2px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">👑 LEADER</span>' : ''}
                        </td>
                        <td style="padding: 12px;">${escapeHtml(p.role || 'member')}</td>
                        <td style="padding: 12px; text-align: center;">
                            ${p.is_leader 
                                ? '<span style="color: #f59e0b; font-size: 20px;">👑</span>' 
                                : `<button class="btn btn-secondary btn-small" onclick="setLeader(${p.id})" title="Make leader">Set Leader</button>`
                            }
                        </td>
                        <td style="padding: 12px; text-align: center;">
                            <button class="btn btn-danger btn-small" onclick="removeParticipant(${p.id})">🗑️</button>
                        </td>
                    </tr>
                `).join('')}
            </tbody>
        </table>
    `;
}

async function openParticipantModal() {
    try {
        const response = await fetch(`${API_PATH}?action=get_available_users&prototype_id=${currentPrototype.id}`);
        const availableUsers = await response.json();
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Add Participant</h3>
                    <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
                </div>
                <form onsubmit="addParticipant(event)">
                    <div class="form-group">
                        <label>Select User</label>
                        <select id="participantUsername" required style="width: 100%; padding: 10px; border: 1px solid #e1e8ed; border-radius: 6px;">
                            <option value="">-- Select a user --</option>
                            ${availableUsers.map(u => `<option value="${escapeHtml(u.username)}">${escapeHtml(u.username)}</option>`).join('')}
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <input type="text" id="participantRole" value="member" placeholder="e.g., Developer, Designer, PM">
                    </div>
                    <div class="form-group">
                        <label style="display: flex; align-items: center; gap: 8px;">
                            <input type="checkbox" id="participantIsLeader">
                            <span>Make this user the project leader</span>
                        </label>
                    </div>
                    <div class="action-bar">
                        <button type="submit" class="btn btn-primary">Add Participant</button>
                        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    } catch (error) {
        console.error('Error loading users:', error);
        alert('Error loading available users');
    }
}

async function addParticipant(event) {
    event.preventDefault();
    
    const username = document.getElementById('participantUsername').value;
    const role = document.getElementById('participantRole').value;
    const isLeader = document.getElementById('participantIsLeader').checked;
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'add_participant',
                prototype_id: currentPrototype.id,
                username: username,
                role: role,
                is_leader: isLeader
            })
        });
        
        const result = await response.json();
        if (result.success) {
            document.querySelector('.modal').remove();
            loadParticipants();
        } else if (result.error) {
            alert(result.error);
        }
    } catch (error) {
        console.error('Error adding participant:', error);
        alert('Error adding participant');
    }
}

async function setLeader(participantId) {
    if (!confirm('Set this user as project leader?')) return;
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'set_leader',
                prototype_id: currentPrototype.id,
                participant_id: participantId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            loadParticipants();
        }
    } catch (error) {
        console.error('Error setting leader:', error);
        alert('Error setting leader');
    }
}

async function removeParticipant(participantId) {
    if (!confirm('Remove this participant?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'remove_participant');
        formData.append('id', participantId);
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            loadParticipants();
        }
    } catch (error) {
        console.error('Error removing participant:', error);
        alert('Error removing participant');
    }
}