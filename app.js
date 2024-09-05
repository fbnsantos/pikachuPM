// Tarefas armazenadas em localStorage
const taskList = JSON.parse(localStorage.getItem('tasks')) || [];

// Inicialização do Kanban
document.addEventListener('DOMContentLoaded', () => {
    renderTasks();
});

// Renderiza as tarefas nas colunas
function renderTasks() {
    ['backlog-items', 'todo-items', 'in-progress-items', 'review-items', 'done-items'].forEach(id => {
        document.getElementById(id).innerHTML = '';
    });

    taskList.forEach(task => {
        const taskElement = document.createElement('div');
        taskElement.className = 'kanban-item';
        taskElement.draggable = true;
        taskElement.innerHTML = `
            <strong>${task.title}</strong><br>
            ${task.description ? task.description : ''}<br>
            <em>Duração: ${task.duration}</em>
            <br>Dependência: ${task.parent_task_id ? task.parent_task_id : 'Nenhuma'}
        `;

        document.getElementById(`${task.status}-items`).appendChild(taskElement);
    });
}

// Modal de Tarefa
const taskModal = document.getElementById('taskModal');

function openModal() {
    taskModal.style.display = 'block';
}

function closeModal() {
    taskModal.style.display = 'none';
}

// Submissão de nova tarefa
document.getElementById('taskForm').addEventListener('submit', function(e) {
    e.preventDefault();
    
    const title = document.getElementById('task-title').value;
    const description = document.getElementById('task-description').value;
    const duration = document.getElementById('task-duration').value;
    const parentTaskId = document.getElementById('task-parent').value;
    const task = {
        id: taskList.length + 1,
        title,
        description,
        duration,
        parent_task_id: parentTaskId ? parseInt(parentTaskId) : null,
        status: 'backlog'
    };
    
    taskList.push(task);
    localStorage.setItem('tasks', JSON.stringify(taskList));
    closeModal();
    renderTasks();
});
