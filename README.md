
# pikachuPM - Research Project Management System

A comprehensive PHP-based project management system tailored for research environments, particularly robotics and IoT laboratories. The system provides an integrated platform for managing prototypes, sprints, tasks, research ideas, leads, and publications through a cohesive web interface.

## 🎯 Overview

pikachuPM is designed to support agile methodologies adapted for research workflows, enabling experimental prototype development, collaborative research management, and academic output tracking. It integrates multiple interconnected modules that work together to provide a complete research project management solution.

## ✨ Key Features

### 📦 Prototype Management
- Hierarchical prototype tracking with parent-child relationships
- User story management with completion percentages
- Sprint and task association for prototypes
- File attachment support for documentation
- Detailed prototype history and progress tracking

### 🏃 Sprint Management
- Complete sprint lifecycle management (Planning → Execution → Review → Retrospective)
- Automatic generation of standard sprint management tasks
- Multi-entity association (prototypes, projects, tasks)
- Team member assignment with roles
- Sprint status tracking (open, in progress, paused, completed)
- Gantt chart visualization for timeline management

### ✅ Task Management (To-Do System)
- Kanban-style task boards with drag-and-drop
- Four task states: `aberta` (open), `em execução` (in progress), `suspensa` (paused), `concluída` (completed)
- Task assignment and responsibility tracking
- Deadline management with visual indicators
- Rich text descriptions with markdown support
- Task filtering and search capabilities

### 💡 Research Ideas
- Centralized research opportunity tracking
- Priority and status management
- Collaborative interest tracking
- Link collection for related resources
- Author and contributor tracking

### 🎓 PhD Progress Monitoring
- Individual PhD student tracking
- Publication management (articles, conferences, datasets, code, patents)
- Research stage tracking (planned, in execution, waiting, completed)
- Supervisor and milestone tracking
- Timeline visualization

### 📊 Project Management
- Project organization with deliverables
- Multi-sprint and multi-prototype association
- Team collaboration features
- Project status tracking
- Integration with research milestones

### 📈 Leads Management
- Commercial contact tracking
- Lead status pipeline
- Follow-up management
- Integration with financial tracking

### 🔗 Integration Features
- Redmine API integration for user management
- File upload system supporting multiple formats
- Calendar integration for meetings and deadlines
- Daily meeting countdown timer
- Customizable color themes

## 🛠️ Technology Stack

- **Backend**: PHP 7.4+ with PDO
- **Database**: MySQL/MariaDB
- **Frontend**: Bootstrap 5, JavaScript
- **Libraries**: 
  - marked.js for Markdown rendering
  - Custom drag-and-drop implementations
  - AJAX for dynamic content loading

## 📋 Requirements

- PHP 7.4 or higher
- MySQL 5.7 or MariaDB 10.2+
- Apache/Nginx web server
- PDO PHP extension
- MySQL PHP extension

## 🚀 Installation

1. **Clone the repository**
   ```bash
   git clone [repository-url]
   cd pikachuPM
   ```

2. **Configure database connection**
   
   Copy and edit the configuration file:
   ```bash
   cp config.example.php config.php
   ```
   
   Update `config.php` with your database credentials:
   ```php
   $db_host = 'localhost';
   $db_name = 'your_database_name';
   $db_user = 'your_database_user';
   $db_pass = 'your_database_password';
   ```

3. **Import database schema**
   
   The system automatically creates required tables on first run. Alternatively, you can manually import:
   ```bash
   mysql -u your_user -p your_database < database/schema.sql
   ```

4. **Configure web server**
   
   Point your web server document root to the project directory.
   
   Example Apache configuration:
   ```apache
   <VirtualHost *:80>
       DocumentRoot /path/to/pikachuPM
       ServerName your-domain.com
       
       <Directory /path/to/pikachuPM>
           AllowOverride All
           Require all granted
       </Directory>
   </VirtualHost>
   ```

5. **Set file permissions**
   ```bash
   chmod -R 755 pikachuPM/
   chmod -R 777 uploads/  # For file uploads
   ```

6. **Access the system**
   
   Navigate to your configured domain/IP in a web browser and log in with your credentials.

## 📖 Usage Guide

### Managing Prototypes

1. Navigate to the **Prototypes** tab
2. Click "New Prototype" to create a new entry
3. Add user stories with descriptions and acceptance criteria
4. Associate sprints and tasks as needed
5. Upload documentation and related files
6. Track completion percentage for each story

### Creating and Managing Sprints

1. Go to the **Sprints** tab
2. Click "New Sprint" and fill in details:
   - Sprint name and description
   - Start and end dates
   - Responsible person
3. Use "Generate Standard Tasks" to create sprint management tasks:
   - Sprint Planning
   - Development/Execution
   - Daily Stand-ups
   - Testing & Validation
   - Sprint Review
   - Sprint Retrospective
4. Associate prototypes, projects, and team members
5. Track sprint progress through the status field

### Task Management with Kanban

1. Access the **To-Do** tab
2. View tasks organized by status columns
3. Drag and drop tasks between states
4. Click on a task to view/edit details
5. Use filters to find specific tasks
6. Assign tasks to team members with deadlines

### Research Ideas Tracking

1. Navigate to **Research Ideas** tab
2. Click "New Idea" to propose a research opportunity
3. Set priority (low, normal, high, urgent)
4. Add interested collaborators
5. Attach relevant links and resources
6. Update status as the idea progresses

### PhD Progress Monitoring

1. Go to **PhD Plan** tab
2. Select a PhD student from the dropdown
3. Add/edit PhD information (supervisor, start date, expected completion)
4. Track publications by type (article, conference, dataset, code, patent)
5. Manage research tasks across stages
6. Monitor overall progress toward graduation

## 🏗️ System Architecture

### Database Structure

The system uses a relational database with the following key tables:

- **prototypes**: Main prototype information
- **prototype_stories**: User stories for prototypes
- **sprints**: Sprint management
- **sprint_members**: Team assignments
- **sprint_tasks**: Task-sprint associations
- **todos**: Task management
- **projects**: Research projects
- **research_ideas**: Research opportunities
- **phd_info**: PhD student information
- **phd_artigos**: Academic publications

Junction tables connect entities for many-to-many relationships:
- `sprint_prototypes`
- `sprint_projects`
- `project_prototypes`
- `deliverable_sprints`
- `deliverable_tasks`

### File Structure

```
pikachuPM/
├── index.php                 # Main entry point
├── login.php                 # Authentication
├── config.php                # Database configuration
├── tabs/                     # Module implementations
│   ├── prototypes/
│   │   └── prototypesv2.php
│   ├── sprints.php
│   ├── todos.php
│   ├── projectos.php
│   ├── research_ideas.php
│   ├── phd_kanban.php
│   ├── leads.php
│   └── gantt.php
├── uploads/                  # File storage
├── assets/                   # Static resources
└── README.md
```

### Design Patterns

**Two-Panel Layout**: Most modules follow a consistent pattern:
- Left sidebar: Filterable list of items
- Right panel: Detailed view with edit capabilities

**Modal-Based Editing**: Create and edit operations use Bootstrap modals for a seamless user experience without page reloads.

**AJAX Communication**: Separate endpoint files handle AJAX requests and return clean JSON responses.

**State Management**: Consistent state nomenclature across all modules ensures integration compatibility.

## 🔒 Security Considerations

- PDO prepared statements prevent SQL injection
- Session-based authentication
- File upload validation (blocks dangerous executables)
- XSS prevention through proper output escaping
- Permission system controls task visibility and editing

## 🎨 Customization

### Changing Theme Colors

1. Navigate to **Administration** → **Theme Settings**
2. Select gradient colors for the interface
3. Changes apply system-wide immediately

### Adding Custom Modules

To add a new module:

1. Create a new PHP file in the `tabs/` directory
2. Implement the two-panel layout pattern
3. Add database table creation logic
4. Register the tab in `index.php`:
   ```php
   $tabs = [
       // ... existing tabs
       'your_module' => 'Your Module Name'
   ];
   ```

## 🤝 Contributing

Contributions are welcome! Please follow these guidelines:

1. Follow the existing code style and patterns
2. Test thoroughly before submitting
3. Document new features in this README
4. Use meaningful commit messages

## 📝 Best Practices

### Database Operations
- Always use PDO with prepared statements
- Implement try-catch blocks for error handling
- Use transactions for multi-step operations
- Create indexes for frequently queried columns

### User Interface
- Maintain consistency with existing patterns
- Provide clear feedback for user actions
- Implement loading indicators for async operations
- Use responsive design for mobile compatibility

### Task State Management
- Use standardized states: `aberta`, `em execução`, `suspensa`, `concluída`
- Ensure state transitions are logical and clear
- Provide visual indicators for each state

## 🐛 Troubleshooting

### Database Connection Issues
- Verify credentials in `config.php`
- Check MySQL service status
- Ensure database exists and user has proper permissions

### File Upload Problems
- Check `uploads/` directory permissions (777)
- Verify PHP upload settings in `php.ini`:
  ```ini
  upload_max_filesize = 20M
  post_max_size = 25M
  ```

### Session Timeout
- Sessions are configured for 24-hour duration
- Check PHP session settings if experiencing early timeouts

## 📚 Additional Resources

- Bootstrap 5 Documentation: https://getbootstrap.com/docs/5.0/
- PHP PDO Documentation: https://www.php.net/manual/en/book.pdo.php
- Markdown Guide: https://www.markdownguide.org/

## 📄 License

[Specify your license here]

## 👥 Authors and Acknowledgments

Developed by Filipe for research laboratory project management needs.

## 🔄 Version History

- **v2.0**: Comprehensive research ideas module, improved file handling
- **v1.5**: PhD monitoring integration, standardized task states
- **v1.0**: Initial release with prototypes, sprints, and tasks

## 📞 Support

For issues, questions, or suggestions, please contact the development team or open an issue in the project repository.

---

**pikachuPM** - Empowering research teams with agile project management.n in progress*

