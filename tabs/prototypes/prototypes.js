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
                 data-prototype-id="${p.id}">
                <h3>${escapeHtml(p.short_name)}</h3>
                <p>${escapeHtml(p.title)}</p>
            </div>
        `).join('');
        
        // Adicionar event listeners após renderizar
        document.querySelectorAll('.prototype-item').forEach(item => {
            item.addEventListener('click', function(e) {
                const id = this.getAttribute('data-prototype-id');
                selectPrototype(id, this);
            });
        });
    } catch (error) {
        console.error('Error loading prototypes:', error);
    }
}

async function selectPrototype(id, clickedElement) {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        console.log('Prototype selected:', currentPrototype);
        
        renderPrototypeDetail();
        loadStories();
        
        // Update active state
        document.querySelectorAll('.prototype-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Tentar adicionar classe active de várias formas
        if (clickedElement && typeof clickedElement === 'object' && clickedElement.classList) {
            // Caso 1: elemento passado diretamente
            clickedElement.classList.add('active');
        } else if (typeof window !== 'undefined' && window.event && window.event.currentTarget) {
            // Caso 2: usar event global (fallback para código antigo)
            window.event.currentTarget.classList.add('active');
        } else {
            // Caso 3: procurar pelo ID no DOM
            const activeItem = document.querySelector(`.prototype-item[onclick*="selectPrototype(${id}"]`);
            if (activeItem) {
                activeItem.classList.add('active');
            }
        }
    } catch (error) {
        console.error('Error loading prototype:', error);
    }
}

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
                    <div class="vision-content" id="view-vision">
                        ${formatText(currentPrototype.vision) || '<em class="text-muted">Not defined</em>'}
                    </div>
                </div>

                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Target Group</h4>
                        <button class="edit-btn" onclick="editField('targetGroup', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-targetGroup">
                        ${formatText(currentPrototype.target_group) || '<em class="text-muted">Not defined</em>'}
                    </div>
                </div>

                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Needs (Problems to Solve)</h4>
                        <button class="edit-btn" onclick="editField('needs', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-needs">
                        ${formatText(currentPrototype.needs) || '<em class="text-muted">Not defined</em>'}
                    </div>
                </div>

                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Product Description</h4>
                        <button class="edit-btn" onclick="editField('productDescription', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-productDescription">
                        ${formatText(currentPrototype.product_description) || '<em class="text-muted">Not defined</em>'}
                    </div>
                </div>

                <div class="vision-card">
                    <div class="vision-header">
                        <h4>Business Goals</h4>
                        <button class="edit-btn" onclick="editField('businessGoals', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="vision-content" id="view-businessGoals">
                        ${formatText(currentPrototype.business_goals) || '<em class="text-muted">Not defined</em>'}
                    </div>
                </div>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-header">
                <h3>💡 Product Statement</h3>
                <button class="edit-btn" onclick="editField('sentence', 'textarea')" title="Edit">✏️</button>
            </div>
            <div class="statement-box" id="view-sentence">
                ${formatText(currentPrototype.sentence) || '<em class="text-muted">Not defined</em>'}
            </div>
            <div class="statement-hint">
                <small>Template: For [target customer], Who [customer needs], The [product name] Is a [product category] That [benefits]. Unlike [competitor], Our product [difference].</small>
            </div>
        </div>

        <div class="detail-section">
            <div class="section-header">
                <h3>🔗 Resources</h3>
            </div>
            <div class="resources-grid">
                <div class="resource-card">
                    <div class="resource-header">
                        <h4>🗂️ Repository Links</h4>
                        <button class="edit-btn" onclick="editField('repoLinks', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="resource-content" id="view-repoLinks">
                        ${formatLinks(currentPrototype.repo_links) || '<em class="text-muted">No links added</em>'}
                    </div>
                </div>

                <div class="resource-card">
                    <div class="resource-header">
                        <h4>📚 Documentation Links</h4>
                        <button class="edit-btn" onclick="editField('documentationLinks', 'textarea')" title="Edit">✏️</button>
                    </div>
                    <div class="resource-content" id="view-documentationLinks">
                        ${formatLinks(currentPrototype.documentation_links) || '<em class="text-muted">No links added</em>'}
                    </div>
                </div>
            </div>
        </div>

        <div class="detail-section">
            <h3>📝 User Stories</h3>
            <div class="filter-bar">
                <select id="statusFilter" onchange="loadStories()">
                    <option value="">All Status</option>
                    <option value="open" selected>Open Stories</option>
                    <option value="closed">Closed Stories</option>
                </select>
                <select id="priorityFilter" onchange="loadStories()">
                    <option value="">All Priorities</option>
                    <option value="Must">Must Have</option>
                    <option value="Should">Should Have</option>
                    <option value="Could">Could Have</option>
                    <option value="Won't">Won't Have</option>
                </select>
                <button class="btn btn-primary btn-small" onclick="openStoryModal()">+ Add Story</button>
            </div>
            <div id="storiesList"></div>
        </div>

        <div class="action-bar">
            <button class="btn btn-success" onclick="exportMarkdown()">📄 Export MD</button>
            <button class="btn btn-danger" onclick="deletePrototype()">🗑️ Delete Prototype</button>
        </div>
    `;
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
    const status = document.getElementById('statusFilter')?.value || 'open';
    
    try {
        const url = `${API_PATH}?action=get_stories&prototype_id=${currentPrototype.id}${priority ? `&priority=${priority}` : ''}${status ? `&status=${status}` : ''}`;
        const response = await fetch(url);
        
        if (!response.ok) {
            console.error('API Error:', response.status, response.statusText);
            const errorText = await response.text();
            console.error('Error details:', errorText);
            throw new Error(`HTTP error! status: ${response.status}`);
        }
        
        const data = await response.json();
        
        // Verificar se é um erro retornado pela API
        if (data.error) {
            console.error('API returned error:', data.error);
            throw new Error(data.error);
        }
        
        // Garantir que temos um array
        stories = Array.isArray(data) ? data : [];
        
        const listEl = document.getElementById('storiesList');
        
        if (stories.length === 0) {
            listEl.innerHTML = `
                <div class="empty-state">
                    <h3>No user stories found</h3>
                    <p>${status === 'open' ? 'Add your first user story' : 'No closed stories yet'}</p>
                </div>
            `;
            return;
        }
        
        listEl.innerHTML = stories.map(story => {
            const statusIcon = story.status === 'closed' ? '✅' : '📖';
            const statusClass = story.status === 'closed' ? 'story-closed' : 'story-open';
            const progressColor = story.completion_percentage >= 75 ? '#10b981' : 
                                 story.completion_percentage >= 50 ? '#f59e0b' : 
                                 story.completion_percentage >= 25 ? '#3b82f6' : '#94a3b8';
            
            return `
                <div class="story-item ${story.moscow_priority.toLowerCase()} ${statusClass}">
                    <div class="story-header">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <span class="story-priority priority-${story.moscow_priority.toLowerCase()}">${story.moscow_priority}</span>
                            <span class="story-status">${statusIcon} ${story.status === 'closed' ? 'Closed' : 'Open'}</span>
                        </div>
                        <div class="story-actions">
                            <button class="btn btn-secondary btn-small" onclick="viewStoryTasks(${story.id})" title="Manage Tasks">📋 Tasks (${story.total_tasks || 0})</button>
                            <button class="btn btn-secondary btn-small" onclick="viewStorySprints(${story.id})" title="Manage Sprints">🏃 Sprints</button>
                            <button class="btn btn-secondary btn-small" onclick="toggleStoryStatus(${story.id})" title="${story.status === 'open' ? 'Mark as Closed' : 'Reopen Story'}">${story.status === 'open' ? '✓' : '↩'}</button>
                            <button class="btn btn-secondary btn-small" onclick="editStory(${story.id})">✏️</button>
                            <button class="btn btn-danger btn-small" onclick="deleteStory(${story.id})">🗑️</button>
                        </div>
                    </div>
                    <div class="story-text">${escapeHtml(story.story_text)}</div>
                    <div class="story-progress">
                        <div class="progress-bar">
                            <div class="progress-fill" style="width: ${story.completion_percentage}%; background-color: ${progressColor};"></div>
                        </div>
                        <span class="progress-text">${story.completion_percentage}% Complete (${story.completed_tasks || 0}/${story.total_tasks || 0} tasks)</span>
                    </div>
                </div>
            `;
        }).join('');
    } catch (error) {
        console.error('Error loading stories:', error);
        const listEl = document.getElementById('storiesList');
        listEl.innerHTML = `
            <div class="empty-state">
                <h3>Error loading stories</h3>
                <p>${error.message}</p>
                <button class="btn btn-primary" onclick="loadStories()">Retry</button>
            </div>
        `;
    }
}

function openStoryModal(storyId = null) {
    currentStory = storyId ? stories.find(s => s.id === storyId) : null;
    
    document.getElementById('storyModalTitle').textContent = currentStory ? 'Edit User Story' : 'New User Story';
    document.getElementById('storyText').value = currentStory?.story_text || '';
    document.getElementById('storyPriority').value = currentStory?.moscow_priority || 'Should';
    document.getElementById('storyStatus').value = currentStory?.status || 'open';
    
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
    const status = document.getElementById('storyStatus').value;
    
    if (!storyText) {
        alert('Please enter story text');
        return;
    }
    
    const data = {
        prototype_id: currentPrototype.id,
        story_text: storyText,
        moscow_priority: priority,
        status: status
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

async function toggleStoryStatus(id) {
    try {
        const formData = new FormData();
        formData.append('action', 'toggle_story_status');
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
        console.error('Error toggling story status:', error);
        alert('Error updating story status');
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

// ===== SPRINTS =====
async function viewStorySprints(storyId) {
    currentStory = stories.find(s => s.id === storyId);
    
    try {
        const response = await fetch(`${API_PATH}?action=get_story_sprints&story_id=${storyId}`);
        const sprints = await response.json();
        
        const sprintsList = sprints.length > 0 ? sprints.map(sprint => `
            <div class="sprint-item">
                <div style="flex: 1;">
                    <strong>${escapeHtml(sprint.nome)}</strong>
                    <span class="badge badge-${sprint.estado}">${escapeHtml(sprint.estado)}</span>
                    <div style="font-size: 12px; color: #64748b; margin-top: 4px;">
                        ${sprint.data_inicio ? `📅 ${sprint.data_inicio}` : ''} 
                        ${sprint.data_fim ? `→ ${sprint.data_fim}` : ''}
                    </div>
                </div>
                <button class="btn btn-danger btn-small" onclick="unlinkSprint(${sprint.link_id})">Unlink</button>
            </div>
        `).join('') : '<p>No sprints linked to this story yet.</p>';
        
        const modal = document.getElementById('sprintModal');
        modal.querySelector('.modal-content').innerHTML = `
            <div class="modal-header">
                <h3>Sprints for Story #${storyId}</h3>
                <button class="close-modal" onclick="closeSprintModal()">&times;</button>
            </div>
            <div class="sprint-list">
                ${sprintsList}
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="showLinkSprintForm(${storyId})">+ Link Sprint</button>
                <button class="btn btn-secondary" onclick="closeSprintModal()">Close</button>
            </div>
        `;
        modal.classList.add('active');
    } catch (error) {
        console.error('Error loading sprints:', error);
    }
}

async function showLinkSprintForm(storyId) {
    try {
        const response = await fetch(`${API_PATH}?action=get_available_sprints&story_id=${storyId}`);
        const availableSprints = await response.json();
        
        if (availableSprints.length === 0) {
            alert('No available sprints to link. Please create a sprint first.');
            return;
        }
        
        const sprintOptions = availableSprints.map(sprint => 
            `<option value="${sprint.id}">${escapeHtml(sprint.nome)} (${sprint.estado})</option>`
        ).join('');
        
        const modal = document.getElementById('sprintModal');
        modal.querySelector('.modal-content').innerHTML = `
            <div class="modal-header">
                <h3>Link Sprint to Story #${storyId}</h3>
                <button class="close-modal" onclick="closeSprintModal()">&times;</button>
            </div>
            <div class="form-group">
                <label>Select Sprint</label>
                <select id="selectSprint">
                    ${sprintOptions}
                </select>
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="linkSprint(${storyId})">Link Sprint</button>
                <button class="btn btn-secondary" onclick="viewStorySprints(${storyId})">← Back</button>
            </div>
        `;
    } catch (error) {
        console.error('Error loading available sprints:', error);
    }
}

async function linkSprint(storyId) {
    const sprintId = document.getElementById('selectSprint').value;
    
    if (!sprintId) {
        alert('Please select a sprint');
        return;
    }
    
    try {
        const response = await fetch(`${API_PATH}?action=link_sprint`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ story_id: storyId, sprint_id: sprintId })
        });
        
        const result = await response.json();
        if (result.success) {
            viewStorySprints(storyId);
        }
    } catch (error) {
        console.error('Error linking sprint:', error);
        alert('Error linking sprint');
    }
}

async function unlinkSprint(linkId) {
    if (!confirm('Unlink this sprint from the story?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'unlink_sprint');
        formData.append('id', linkId);
        
        const response = await fetch(`${API_PATH}`, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            viewStorySprints(currentStory.id);
        }
    } catch (error) {
        console.error('Error unlinking sprint:', error);
        alert('Error unlinking sprint');
    }
}

function closeSprintModal() {
    document.getElementById('sprintModal').classList.remove('active');
}

// ===== TASKS =====
async function viewStoryTasks(storyId) {
    currentStory = stories.find(s => s.id === storyId);
    
    try {
        const response = await fetch(`${API_PATH}?action=get_story_tasks&story_id=${storyId}`);
        const tasks = await response.json();
        
        const tasksList = tasks.length > 0 ? tasks.map(task => {
            const statusBadge = task.estado === 'concluida' ? 'success' : 
                              task.estado === 'em_execucao' ? 'warning' : 'info';
            return `
                <div class="task-item">
                    <div style="flex: 1;">
                        <strong>${escapeHtml(task.titulo || 'Task #' + task.id)}</strong>
                        <span class="badge badge-${statusBadge}">${escapeHtml(task.estado || 'aberta')}</span>
                        ${task.descritivo ? `<div style="font-size: 12px; color: #64748b; margin-top: 4px;">${escapeHtml(task.descritivo).substring(0, 100)}${task.descritivo.length > 100 ? '...' : ''}</div>` : ''}
                    </div>
                    <button class="btn btn-danger btn-small" onclick="unlinkTask(${task.link_id})">Unlink</button>
                </div>
            `;
        }).join('') : '<p>No tasks linked to this story yet.</p>';
        
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
                <button class="btn btn-success" onclick="showLinkExistingTaskForm(${storyId})">🔗 Link Existing Task</button>
                <button class="btn btn-secondary" onclick="closeTaskModal()">Close</button>
            </div>
        `;
        modal.classList.add('active');
    } catch (error) {
        console.error('Error loading tasks:', error);
    }
}

async function showLinkExistingTaskForm(storyId) {
    try {
        const response = await fetch(`${API_PATH}?action=get_available_tasks&story_id=${storyId}`);
        const availableTasks = await response.json();
        
        if (availableTasks.length === 0) {
            alert('No available tasks to link.');
            return;
        }
        
        const taskOptions = availableTasks.map(task => 
            `<option value="${task.id}">${escapeHtml(task.titulo)} (${task.estado})</option>`
        ).join('');
        
        const modal = document.getElementById('taskModal');
        modal.querySelector('.modal-content').innerHTML = `
            <div class="modal-header">
                <h3>Link Existing Task to Story #${storyId}</h3>
                <button class="close-modal" onclick="closeTaskModal()">&times;</button>
            </div>
            <div class="form-group">
                <label>Select Task</label>
                <select id="selectTask">
                    ${taskOptions}
                </select>
            </div>
            <div class="action-bar">
                <button class="btn btn-primary" onclick="linkExistingTask(${storyId})">Link Task</button>
                <button class="btn btn-secondary" onclick="viewStoryTasks(${storyId})">← Back</button>
            </div>
        `;
    } catch (error) {
        console.error('Error loading available tasks:', error);
    }
}

async function linkExistingTask(storyId) {
    const taskId = document.getElementById('selectTask').value;
    
    if (!taskId) {
        alert('Please select a task');
        return;
    }
    
    try {
        const response = await fetch(`${API_PATH}?action=link_task`, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ story_id: storyId, task_id: taskId })
        });
        
        const result = await response.json();
        if (result.success) {
            viewStoryTasks(storyId);
        }
    } catch (error) {
        console.error('Error linking task:', error);
        alert('Error linking task');
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
        description: description
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
            loadStories(); // Atualizar percentagem
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
            loadStories(); // Atualizar percentagem
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