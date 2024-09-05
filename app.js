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

// Function to open tabs
// Abre as Tabs
function openTab(evt, tabName) {
    var i, tabcontent, tablinks;
    tabcontent = document.getElementsByClassName("tabcontent");
    for (i = 0; i < tabcontent.length; i++) {
        tabcontent[i].style.display = "none";
    }
    tablinks = document.getElementsByClassName("tablinks");
    for (i = 0; i < tablinks.length; i++) {
        tablinks[i].className = tablinks[i].className.replace(" active", "");
    }
    document.getElementById(tabName).style.display = "block";
    evt.currentTarget.className += " active";
}

// Drag and Drop
function allowDrop(ev) {
    ev.preventDefault();  // Permitir soltar o item
}

function drag(ev) {
    ev.dataTransfer.setData("text", ev.target.id);  // Armazenar o ID da tarefa que está sendo arrastada
}

function drop(ev) {
    ev.preventDefault();
    var data = ev.dataTransfer.getData("text");
    var draggedElement = document.getElementById(data);
    ev.target.appendChild(draggedElement);  // Adicionar o elemento arrastado na nova coluna
}

// Exibir a primeira tab por padrão
document.getElementsByClassName("tablinks")[0].click();
// Open the first tab by default
document.getElementsByClassName("tablinks")[0].click();

// Google Login - Sign Out Function
function signOut() {
    const auth2 = gapi.auth2.getAuthInstance();
    auth2.signOut().then(function () {
        console.log('User signed out.');
    });
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


function saveTaskState() {
    var todoTasks = document.getElementById('todo').innerHTML;
    var inProgressTasks = document.getElementById('in-progress').innerHTML;
    var doneTasks = document.getElementById('done').innerHTML;
    localStorage.setItem('todoTasks', todoTasks);
    localStorage.setItem('inProgressTasks', inProgressTasks);
    localStorage.setItem('doneTasks', doneTasks);
}

function loadTaskState() {
    document.getElementById('todo').innerHTML = localStorage.getItem('todoTasks');
    document.getElementById('in-progress').innerHTML = localStorage.getItem('inProgressTasks');
    document.getElementById('done').innerHTML = localStorage.getItem('doneTasks');
}

// Chame essa função quando a página for carregada
document.addEventListener('DOMContentLoaded', loadTaskState);

// Salve o estado sempre que a tarefa for movida
document.querySelectorAll('.kanban-column').forEach(column => {
    column.addEventListener('drop', saveTaskState);
});
