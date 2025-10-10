/**
 * PIKACHUPM - PROTOTYPES MODULE
 * Complete JavaScript file with correct function order
 */

// ===== FUNÇÕES AUXILIARES (DEVEM VIR PRIMEIRO!) =====

function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function makeLinksClickable(text) {
    if (!text) return '';
    
    // Regex para detectar URLs
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    
    // Substitui URLs por links clicáveis
    return text.replace(urlRegex, (url) => {
        return `<a href="${url}" target="_blank" rel="noopener noreferrer" style="color: #3b82f6; text-decoration: underline;">${url}</a>`;
    });
}

function formatTextWithLinks(text) {
    if (!text) return '';
    return makeLinksClickable(escapeHtml(text)).replace(/\n/g, '<br>');
}

// ===== GLOBAL VARIABLES =====
const API_PATH = 'tabs/prototypes/prototypes_api.php';
let currentPrototype = null;
let currentStory = null;
let stories = [];
let participants = [];

// ===== INITIALIZATION =====
document.addEventListener('DOMContentLoaded', function() {
    loadPrototypes();
});

function createNewPrototype() {
    console.log('createNewPrototype() chamada!');
    try {
        openPrototypeModal();
    } catch (error) {
        console.error('Erro ao abrir modal:', error);
        alert('Erro ao abrir modal: ' + error.message);
    }
}

// ===== PROTOTYPES =====

async function loadPrototypes() {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototypes`);
        const prototypes = await response.json();
        
        const listEl = document.getElementById('prototypesList');
        
        if (prototypes.length === 0) {
            listEl.innerHTML = `
                <div class="empty-state">
                    <h3>No prototypes yet</h3>
                    <p>Create your first prototype</p>
                </div>
            `;
            return;
        }
        
        listEl.innerHTML = prototypes.map(p => `
            <div class="prototype-item ${currentPrototype?.id === p.id ? 'active' : ''}" 
                 onclick="selectPrototype(${p.id}, event)">
                <h3>${escapeHtml(p.short_name)}</h3>
                <p>${escapeHtml(p.title)}</p>
            </div>
        `).join('');
    } catch (error) {
        console.error('Error loading prototypes:', error);
    }
}

async function selectPrototype(id, clickEvent) {
    try {
        const response = await fetch(`${API_PATH}?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        console.log('Prototype selected:', currentPrototype);
        
        renderPrototypeDetail();
        loadStories();
        loadParticipants();
        
        // Update active state
        document.querySelectorAll('.prototype-item').forEach(item => {
            item.classList.remove('active');
        });
        
        // Adicionar classe active ao item clicado
        if (clickEvent && clickEvent.currentTarget) {
            clickEvent.currentTarget.classList.add('active');
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
                    <button class="edit-btn" onclick="editField('short_name', 'text')" title="Edit">✏️</button>
                </div>
                <div class="info-item">
                    <div class="info-label">Title</div>
                    <div class="info-value" id="view-title">${escapeHtml(currentPrototype.title || 'Not defined')}</div>
                    <button class="edit-btn" onclick="editField('title', 'text')" title="Edit">✏️</button>
                </div>
            </div>
            
            <!-- Team Participants -->
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

// ===== INLINE EDITING =====

async function editField(fieldName, inputType) {
    const viewElement = document.getElementById(`view-${fieldName}`);
    if (!viewElement) return;
    
    const currentValue = currentPrototype[fieldName] || '';
    const parent = viewElement.parentElement;
    
    // Salvar o conteúdo original
    const originalContent = viewElement.innerHTML;
    
    // Criar input
    let inputElement;
    if (inputType === 'textarea') {
        inputElement = document.createElement('textarea');
        inputElement.style.minHeight = '100px';
        inputElement.style.width = '100%';
        inputElement.style.padding = '10px';
        inputElement.style.border = '2px solid #3b82f6';
        inputElement.style.borderRadius = '6px';
        inputElement.style.fontFamily = 'inherit';
        inputElement.style.fontSize = '14px';
    } else {
        inputElement = document.createElement('input');
        inputElement.type = 'text';
        inputElement.style.width = '100%';
        inputElement.style.padding = '10px';
        inputElement.style.border = '2px solid #3b82f6';
        inputElement.style.borderRadius = '6px';
        inputElement.style.fontSize = '14px';
    }
    
    inputElement.value = currentValue;
    inputElement.id = `edit-${fieldName}`;
    
    // Substituir o elemento de visualização
    viewElement.replaceWith(inputElement);
    inputElement.focus();
    
    // Criar botões de ação
    const actionDiv = document.createElement('div');
    actionDiv.style.marginTop = '10px';
    actionDiv.style.display = 'flex';
    actionDiv.style.gap = '10px';
    actionDiv.innerHTML = `
        <button class="btn btn-primary btn-small" onclick="saveFieldEdit('${fieldName}', '${inputType}')">💾 Save</button>
        <button class="btn btn-secondary btn-small" onclick="cancelFieldEdit('${fieldName}', '${inputType}', \`${originalContent.replace(/`/g, '\\`')}\`)">❌ Cancel</button>
    `;
    
    inputElement.after(actionDiv);
    
    // Ocultar botão de editar
    const editBtn = parent.querySelector('.edit-btn');
    if (editBtn) editBtn.style.display = 'none';
}

async function saveFieldEdit(fieldName, inputType) {
    const inputElement = document.getElementById(`edit-${fieldName}`);
    if (!inputElement) return;
    
    const newValue = inputElement.value;
    
    try {
        const data = {
            action: 'update_prototype',
            id: currentPrototype.id,
            [fieldName]: newValue
        };
        
        // Incluir todos os campos obrigatórios
        const requiredFields = ['short_name', 'title', 'vision', 'target_group', 'needs', 
                                'product_description', 'business_goals', 'sentence', 
                                'repo_links', 'documentation_links'];
        
        requiredFields.forEach(field => {
            if (field !== fieldName) {
                data[field] = currentPrototype[field] || '';
            }
        });
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            currentPrototype[fieldName] = newValue;
            cancelFieldEdit(fieldName, inputType, formatTextWithLinks(newValue || 'Not defined'));
        } else {
            alert('Error saving changes');
        }
    } catch (error) {
        console.error('Error saving field:', error);
        alert('Error saving changes');
    }
}

function cancelFieldEdit(fieldName, inputType, originalContent) {
    const inputElement = document.getElementById(`edit-${fieldName}`);
    if (!inputElement) return;
    
    // Remover botões de ação
    const actionDiv = inputElement.nextElementSibling;
    if (actionDiv) actionDiv.remove();
    
    // Restaurar elemento de visualização
    const viewElement = document.createElement('div');
    viewElement.className = inputType === 'textarea' ? 'vision-content' : 'info-value';
    viewElement.id = `view-${fieldName}`;
    viewElement.innerHTML = originalContent;
    
    inputElement.replaceWith(viewElement);
    
    // Mostrar botão de editar novamente
    const parent = viewElement.parentElement;
    const editBtn = parent.querySelector('.edit-btn');
    if (editBtn) editBtn.style.display = 'block';
}

function openPrototypeModal(prototypeId = null) {
    console.log('openPrototypeModal() chamada com ID:', prototypeId);
    
    currentPrototype = prototypeId ? currentPrototype : null;
    
    console.log('Criando modal...');
    const modal = document.createElement('div');
    modal.className = 'modal active';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>${currentPrototype ? 'Edit Prototype' : 'New Prototype'}</h3>
                <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <form onsubmit="savePrototype(event)">
                <div class="form-row">
                    <div class="form-group">
                        <label for="protoShortName">Short Name *</label>
                        <input type="text" id="protoShortName" value="${escapeHtml(currentPrototype?.short_name || '')}" required>
                    </div>
                    <div class="form-group">
                        <label for="protoTitle">Title *</label>
                        <input type="text" id="protoTitle" value="${escapeHtml(currentPrototype?.title || '')}" required>
                    </div>
                </div>
                
                <div class="form-group">
                    <label for="protoVision">Vision</label>
                    <textarea id="protoVision">${escapeHtml(currentPrototype?.vision || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoSentence">Product Statement</label>
                    <textarea id="protoSentence">${escapeHtml(currentPrototype?.sentence || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoTargetGroup">Target Group</label>
                    <textarea id="protoTargetGroup">${escapeHtml(currentPrototype?.target_group || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoNeeds">Needs</label>
                    <textarea id="protoNeeds">${escapeHtml(currentPrototype?.needs || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoProductDescription">Product Description</label>
                    <textarea id="protoProductDescription">${escapeHtml(currentPrototype?.product_description || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoBusinessGoals">Business Goals</label>
                    <textarea id="protoBusinessGoals">${escapeHtml(currentPrototype?.business_goals || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoRepoLinks">Repository Links</label>
                    <textarea id="protoRepoLinks">${escapeHtml(currentPrototype?.repo_links || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label for="protoDocumentationLinks">Documentation Links</label>
                    <textarea id="protoDocumentationLinks">${escapeHtml(currentPrototype?.documentation_links || '')}</textarea>
                </div>
                
                <div class="action-bar">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                </div>
            </form>
        </div>
    `;
    
    console.log('Adicionando modal ao body...');
    document.body.appendChild(modal);
    console.log('Modal adicionado! Deve estar visível agora.');
}

async function savePrototype(event) {
    event.preventDefault();
    
    console.log('savePrototype() chamada!');
    
    const data = {
        short_name: document.getElementById('protoShortName').value,
        title: document.getElementById('protoTitle').value,
        vision: document.getElementById('protoVision').value,
        sentence: document.getElementById('protoSentence').value,
        target_group: document.getElementById('protoTargetGroup').value,
        needs: document.getElementById('protoNeeds').value,
        product_description: document.getElementById('protoProductDescription').value,
        business_goals: document.getElementById('protoBusinessGoals').value,
        repo_links: document.getElementById('protoRepoLinks').value,
        documentation_links: document.getElementById('protoDocumentationLinks').value
    };
    
    console.log('Dados do formulário:', data);
    
    if (currentPrototype) {
        data.id = currentPrototype.id;
        data.action = 'update_prototype';
        console.log('Modo: UPDATE');
    } else {
        data.action = 'create_prototype';
        console.log('Modo: CREATE');
    }
    
    console.log('Enviando para API:', API_PATH);
    console.log('Payload completo:', data);
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        console.log('Response status:', response.status);
        console.log('Response ok:', response.ok);
        
        const result = await response.json();
        console.log('Response data:', result);
        
        if (result.success) {
            console.log('✅ Protótipo salvo com sucesso!');
            document.querySelector('.modal').remove();
            loadPrototypes();
            if (result.id) {
                console.log('Selecionando protótipo criado:', result.id);
                selectPrototype(result.id);
            } else if (currentPrototype) {
                console.log('Recarregando protótipo atual:', currentPrototype.id);
                selectPrototype(currentPrototype.id);
            }
        } else {
            console.error('❌ Erro na resposta:', result);
            alert('Error: ' + (result.error || 'Unknown error'));
        }
    } catch (error) {
        console.error('❌ Erro ao salvar prototype:', error);
        alert('Error saving prototype: ' + error.message);
    }
}

async function deletePrototype() {
    if (!currentPrototype) return;
    
    if (!confirm(`Delete prototype "${currentPrototype.short_name}"?\n\nThis will also delete all associated user stories.`)) {
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

// ===== PARTICIPANTS MANAGEMENT =====

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
    
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>${currentStory ? 'Edit User Story' : 'New User Story'}</h3>
                <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <form onsubmit="saveStory(event)">
                <div class="form-group">
                    <label>Story Text *</label>
                    <textarea id="storyText" required placeholder="As a [user type], I want [goal] so that [benefit]">${escapeHtml(currentStory?.story_text || '')}</textarea>
                </div>
                
                <div class="form-group">
                    <label>MoSCoW Priority *</label>
                    <select id="storyPriority" required>
                        <option value="Must" ${currentStory?.moscow_priority === 'Must' ? 'selected' : ''}>Must Have</option>
                        <option value="Should" ${currentStory?.moscow_priority === 'Should' ? 'selected' : ''}>Should Have</option>
                        <option value="Could" ${currentStory?.moscow_priority === 'Could' ? 'selected' : ''}>Could Have</option>
                        <option value="Won't" ${currentStory?.moscow_priority === "Won't" ? 'selected' : ''}>Won't Have</option>
                    </select>
                </div>
                
                <div class="action-bar">
                    <button type="submit" class="btn btn-primary">Save</button>
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

function editStory(storyId) {
    openStoryModal(storyId);
}

async function saveStory(event) {
    event.preventDefault();
    
    const data = {
        prototype_id: currentPrototype.id,
        story_text: document.getElementById('storyText').value,
        moscow_priority: document.getElementById('storyPriority').value
    };
    
    if (currentStory) {
        data.id = currentStory.id;
        data.action = 'update_story';
    } else {
        data.action = 'create_story';
    }
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            document.querySelector('.modal').remove();
            loadStories();
        }
    } catch (error) {
        console.error('Error saving story:', error);
        alert('Error saving story');
    }
}

async function deleteStory(storyId) {
    if (!confirm('Delete this user story?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'delete_story');
        formData.append('id', storyId);
        
        const response = await fetch(API_PATH, {
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

// ===== STORY TASKS =====

async function viewStoryTasks(storyId) {
    currentStory = stories.find(s => s.id === storyId);
    
    try {
        const response = await fetch(`${API_PATH}?action=get_story_tasks&story_id=${storyId}`);
        const tasks = await response.json();
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content" style="max-width: 800px;">
                <div class="modal-header">
                    <h3>📋 Tasks for Story #${storyId}</h3>
                    <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
                </div>
                
                <div style="background: #f9fafb; padding: 15px; border-radius: 8px; margin-bottom: 20px;">
                    <p style="margin: 0; color: #64748b; font-size: 14px;">${escapeHtml(currentStory.story_text)}</p>
                </div>
                
                <div style="display: flex; gap: 10px; margin-bottom: 20px;">
                    <button class="btn btn-primary" onclick="openLinkTaskModal(${storyId})">🔗 Link Existing Task</button>
                    <button class="btn btn-primary" onclick="openCreateTaskModal(${storyId})">+ Create New Task</button>
                </div>
                
                <div class="task-list">
                    ${tasks.length === 0 ? '<p style="text-align: center; color: #64748b;">No tasks linked yet</p>' : ''}
                    ${tasks.map(task => `
                        <div class="task-item">
                            <div>
                                <strong>${escapeHtml(task.titulo)}</strong>
                                <span class="badge badge-info">${escapeHtml(task.estado)}</span>
                            </div>
                            <button class="btn btn-danger btn-small" onclick="unlinkTask(${task.link_id}, ${storyId})">🔗 Unlink</button>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
        document.body.appendChild(modal);
    } catch (error) {
        console.error('Error loading story tasks:', error);
        alert('Error loading tasks');
    }
}

async function openLinkTaskModal(storyId) {
    try {
        const response = await fetch(`${API_PATH}?action=get_available_tasks&story_id=${storyId}`);
        const availableTasks = await response.json();
        
        const modal = document.createElement('div');
        modal.className = 'modal';
        modal.innerHTML = `
            <div class="modal-content">
                <div class="modal-header">
                    <h3>Link Existing Task</h3>
                    <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
                </div>
                <form onsubmit="linkTask(event, ${storyId})">
                    <div class="form-group">
                        <label>Select Task</label>
                        <select id="taskToLink" required style="width: 100%; padding: 10px; border: 1px solid #e1e8ed; border-radius: 6px;">
                            <option value="">-- Select a task --</option>
                            ${availableTasks.map(t => `
                                <option value="${t.id}">${escapeHtml(t.titulo)} (${escapeHtml(t.estado)})</option>
                            `).join('')}
                        </select>
                    </div>
                    <div class="action-bar">
                        <button type="submit" class="btn btn-primary">Link Task</button>
                        <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                    </div>
                </form>
            </div>
        `;
        document.body.appendChild(modal);
    } catch (error) {
        console.error('Error loading available tasks:', error);
        alert('Error loading tasks');
    }
}

async function linkTask(event, storyId) {
    event.preventDefault();
    
    const taskId = document.getElementById('taskToLink').value;
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'link_task',
                story_id: storyId,
                task_id: taskId
            })
        });
        
        const result = await response.json();
        if (result.success) {
            document.querySelector('.modal').remove();
            viewStoryTasks(storyId);
        }
    } catch (error) {
        console.error('Error linking task:', error);
        alert('Error linking task');
    }
}

async function unlinkTask(linkId, storyId) {
    if (!confirm('Unlink this task?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'unlink_task');
        formData.append('id', linkId);
        
        const response = await fetch(API_PATH, {
            method: 'POST',
            body: formData
        });
        
        const result = await response.json();
        if (result.success) {
            viewStoryTasks(storyId);
        }
    } catch (error) {
        console.error('Error unlinking task:', error);
        alert('Error unlinking task');
    }
}

async function openCreateTaskModal(storyId) {
    const modal = document.createElement('div');
    modal.className = 'modal';
    modal.innerHTML = `
        <div class="modal-content">
            <div class="modal-header">
                <h3>Create New Task</h3>
                <button class="close-modal" onclick="this.closest('.modal').remove()">×</button>
            </div>
            <form onsubmit="createTaskFromStory(event, ${storyId})">
                <div class="form-group">
                    <label>Task Title *</label>
                    <input type="text" id="newTaskTitle" required placeholder="Enter task title">
                </div>
                <div class="form-group">
                    <label>Description</label>
                    <textarea id="newTaskDescription" placeholder="Enter task description"></textarea>
                </div>
                <div class="action-bar">
                    <button type="submit" class="btn btn-primary">Create & Link Task</button>
                    <button type="button" class="btn btn-secondary" onclick="this.closest('.modal').remove()">Cancel</button>
                </div>
            </form>
        </div>
    `;
    document.body.appendChild(modal);
}

async function createTaskFromStory(event, storyId) {
    event.preventDefault();
    
    const title = document.getElementById('newTaskTitle').value;
    const description = document.getElementById('newTaskDescription').value;
    
    try {
        const response = await fetch(API_PATH, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({
                action: 'create_task_from_story',
                story_id: storyId,
                title: title,
                description: description
            })
        });
        
        const result = await response.json();
        if (result.success) {
            document.querySelector('.modal').remove();
            viewStoryTasks(storyId);
        } else if (result.error) {
            alert('Error: ' + result.error);
        }
    } catch (error) {
        console.error('Error creating task:', error);
        alert('Error creating task');
    }
}

// ===== EXPORT =====

function exportMarkdown() {
    if (!currentPrototype) return;
    window.location.href = `${API_PATH}?action=export_markdown&id=${currentPrototype.id}`;
}

// ===== END OF FILE =====
console.log('Prototypes module loaded successfully');