// prototypes.js
let currentPrototype = null;
let currentStory = null;
let prototypes = [];
let stories = [];

// Inicialização
document.addEventListener('DOMContentLoaded', () => {
    loadPrototypes();
    
    document.getElementById('searchInput').addEventListener('input', (e) => {
        loadPrototypes(e.target.value);
    });
});

// ===== PROTOTYPES =====
async function loadPrototypes(search = '') {
    try {
        const url = `prototypes_api.php?action=get_prototypes${search ? `&search=${encodeURIComponent(search)}` : ''}`;
        const response = await fetch(url);
        prototypes = await response.json();
        
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
        const response = await fetch(`prototypes_api.php?action=get_prototype&id=${id}`);
        currentPrototype = await response.json();
        
        renderPrototypeDetail();
        loadStories();
        
        // Update active state
        document.querySelectorAll('.prototype-item').forEach(item => {
            item.classList.remove('active');
        });
        event.currentTarget?.classList.add('active');
    } catch (error) {
        console.error('Error loading prototype:', error);
    }
}

function renderPrototypeDetail() {
    const panel = document.getElementById('detailPanel');
    
    panel.innerHTML = `
        <div class="detail-section">
            <h3>📋 Basic Information</h3>
            <div class="form-row">
                <div class="form-group">
                    <label>Short Name</label>
                    <input type="text" id="shortName" value="${escapeHtml(currentPrototype.short_name || '')}" 
                           onchange="updatePrototype()">
                </div>
                <div class="form-group">
                    <label>Title</label>
                    <input type="text" id="title" value="${escapeHtml(currentPrototype.title || '')}"
                           onchange="updatePrototype()">
                </div>
            </div>
        </div>

        <div class="detail-section">
            <h3>🎯 Product Vision Board</h3>
            <div class="form-group">
                <label>Vision</label>
                <textarea id="vision" onchange="updatePrototype()">${escapeHtml(currentPrototype.vision || '')}</textarea>
            </div>
            <div class="form-group">
                <label>Target Group</label>
                <textarea id="targetGroup" onchange="updatePrototype()">${escapeHtml(currentPrototype.target_group || '')}</textarea>
            </div>
            <div class="form-group">
                <label>Needs (Problems to Solve)</label>
                <textarea id="needs" onchange="updatePrototype()">${escapeHtml(currentPrototype.needs || '')}</textarea>
            </div>
            <div class="form-group">
                <label>Product Description</label>
                <textarea id="productDescription" onchange="updatePrototype()">${escapeHtml(currentPrototype.product_description || '')}</textarea>
            </div>
            <div class="form-group">
                <label>Business Goals</label>
                <textarea id="businessGoals" onchange="updatePrototype()">${escapeHtml(currentPrototype.business_goals || '')}</textarea>
            </div>
        </div>

        <div class="detail-section">
            <h3>💡 Product Statement</h3>
            <div class="form-group">
                <label>Elevator Pitch</label>
                <textarea id="sentence" onchange="updatePrototype()" 
                          placeholder="For [target customer], Who [customer needs], The [product name] Is a [product category] That [benefits]. Unlike [competitor], Our product [difference].">${escapeHtml(currentPrototype.sentence || '')}</textarea>
            </div>
        </div>

        <div class="detail-section">
            <h3>🔗 Resources</h3>
            <div class="form-group">
                <label>Repository Links</label>
                <textarea id="repoLinks" onchange="updatePrototype()" 
                          placeholder="https://github.com/user/repo">${escapeHtml(currentPrototype.repo_links || '')}</textarea>
            </div>
            <div class="form-group">
                <label>Documentation Links</label>
                <textarea id="documentationLinks" onchange="updatePrototype()"
                          placeholder="https://docs.example.com">${escapeHtml(currentPrototype.documentation_links || '')}</textarea>
            </div>
        </div>

        <div class="detail-section">
            <h3>📝 User Stories</h3>
            <div class="filter-bar">
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
        const response = await fetch('prototypes_api.php?action=update_prototype', {
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
    
    fetch('prototypes_api.php?action=create_prototype', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    })
    .then(response => response.json())
    .then(result => {
        if (result.success) {
            loadPrototypes();
            selectPrototype(result.id);
        }
    })
    .catch(error => {
        console.error('Error creating prototype:', error);
        alert('Error creating prototype');
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
        
        const response = await fetch('prototypes_api.php', {
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
        const url = `prototypes_api.php?action=get_stories&prototype_id=${currentPrototype.id}${priority ? `&priority=${priority}` : ''}`;
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
        
        const response = await fetch(`prototypes_api.php?action=${action}`, {
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
        
        const response = await fetch('prototypes_api.php', {
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
        const response = await fetch(`prototypes_api.php?action=get_story_tasks&story_id=${storyId}`);
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
    currentStory = stories.find(s => s.id === storyId);
    
    const modal = document.getElementById('taskModal');
    modal.querySelector('.modal-content').innerHTML = `
        <div class="modal-header">
            <h3>Create Task from Story</h3>
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
}

async function createTaskFromStory() {
    const title = document.getElementById('taskTitle').value.trim();
    const description = document.getElementById('taskDescription').value.trim();
    const priority = document.getElementById('taskPriority').value;
    
    if (!title) {
        alert('Please enter task title');
        return;
    }
    
    const data = {
        story_id: currentStory.id,
        title: title,
        description: description,
        priority: priority
    };
    
    try {
        const response = await fetch('prototypes_api.php?action=create_task_from_story', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(data)
        });
        
        const result = await response.json();
        if (result.success) {
            alert('Task created successfully!');
            viewStoryTasks(currentStory.id);
        }
    } catch (error) {
        console.error('Error creating task:', error);
        alert('Error creating task');
    }
}

async function unlinkTask(linkId) {
    if (!confirm('Unlink this task from the story?')) return;
    
    try {
        const formData = new FormData();
        formData.append('action', 'unlink_task');
        formData.append('id', linkId);
        
        const response = await fetch('prototypes_api.php', {
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
    window.location.href = `prototypes_api.php?action=export_markdown&id=${currentPrototype.id}`;
}

// ===== UTILITY =====
function escapeHtml(text) {
    if (!text) return '';
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}