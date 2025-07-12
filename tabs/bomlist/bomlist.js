// MUST BE CORRECTED BY FETCHING INSTEAD OF USING STRAIGHT PHP

document.addEventListener('DOMContentLoaded', function() {
    // Função para exportar dados para CSV
    window.exportToCSV = function(type) {
        const data = [];
        let headers = [];
        
        switch(type) {
            case 'components':
                headers = ['ID', 'Denominação', 'Tipo', 'Fabricante', 'Fornecedor', 'Preço', 'Stock'];
                <?php foreach ($components as $component): ?>
                    data.push([
                        '<?= $component['Component_ID'] ?>',
                        '<?= addslashes($component['Denomination']) ?>',
                        '<?= addslashes($component['General_Type']) ?>',
                        '<?= addslashes($component['Manufacturer_Name']) ?>',
                        '<?= addslashes($component['Supplier_Name']) ?>',
                        '<?= $component['Price'] ?? 0 ?>',
                        '<?= $component['Stock_Quantity'] ?>'
                    ]);
                <?php endforeach; ?>
                break;
                
            case 'prototypes':
                headers = ['ID', 'Nome', 'Versão', 'Estado', 'Data Criação'];
                <?php foreach ($prototypes as $prototype): ?>
                    data.push([
                        '<?= $prototype['Prototype_ID'] ?>',
                        '<?= addslashes($prototype['Name']) ?>',
                        '<?= $prototype['Version'] ?>',
                        '<?= $prototype['Status'] ?>',
                        '<?= date('d/m/Y', strtotime($prototype['Created_Date'])) ?>'
                    ]);
                <?php endforeach; ?>
                break;
        }
        
        // Criar CSV
        let csvContent = headers.join(',') + '\n';
        data.forEach(row => {
            csvContent += row.map(field => `"${field}"`).join(',') + '\n';
        });
        
        // Download
        const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
        const link = document.createElement('a');
        const url = URL.createObjectURL(blob);
        link.setAttribute('href', url);
        link.setAttribute('download', `${type}_${new Date().toISOString().split('T')[0]}.csv`);
        link.style.visibility = 'hidden';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    };
    
    // Função para gerar relatório BOM
    window.generateBOMReport = function() {
        const selectedPrototype = '<?= $_GET['prototype_id'] ?? '' ?>';
        if (!selectedPrototype) {
            alert('Por favor, selecione um protótipo na aba de Montagem primeiro.');
            return;
        }
        
        // Redirecionar para página de relatório (seria implementada separadamente)
        window.open(`bom_report.php?prototype_id=${selectedPrototype}`, '_blank');
    };
    
    // Função para importar dados
    window.importFromFile = function() {
        const fileInput = document.getElementById('importFile');
        const file = fileInput.files[0];
        
        if (!file) {
            alert('Por favor, selecione um ficheiro para importar.');
            return;
        }
        
        // Aqui seria implementada a lógica de importação
        alert('Funcionalidade de importação em desenvolvimento.');
    };
    
    // Auto-refresh da página a cada 5 minutos para manter dados atualizados
    setInterval(function() {
        if (document.visibilityState === 'visible') {
            // Verificar se há mudanças na base de dados (poderia ser implementado via AJAX)
        }
    }, 300000); // 5 minutos
    
    // Busca em tempo real
    const searchInputs = document.querySelectorAll('input[type="search"]');
    searchInputs.forEach(input => {
        input.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const table = this.closest('.card').querySelector('tbody');
            const rows = table.querySelectorAll('tr');
            
            rows.forEach(row => {
                const text = row.textContent.toLowerCase();
                row.style.display = text.includes(searchTerm) ? '' : 'none';
            });
        });
    });
    
    // Tooltips para botões
    const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    const tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Confirmação de eliminação melhorada
    const deleteLinks = document.querySelectorAll('a[href*="action=delete"]');
    deleteLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            e.preventDefault();
            const item = this.closest('tr').querySelector('strong').textContent;
            if (confirm(`Tem a certeza que deseja eliminar "${item}"?\n\nEsta ação não pode ser desfeita.`)) {
                window.location.href = this.href;
            }
        });
    });
    
    // Validação de formulários
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            const requiredFields = form.querySelectorAll('[required]');
            let valid = true;
            
            requiredFields.forEach(field => {
                if (!field.value.trim()) {
                    field.classList.add('is-invalid');
                    valid = false;
                } else {
                    field.classList.remove('is-invalid');
                }
            });
            
            if (!valid) {
                e.preventDefault();
                alert('Por favor, preencha todos os campos obrigatórios.');
            }
        });
    });
    
    // Guardar rascunhos automaticamente
    const formInputs = document.querySelectorAll('form input, form select, form textarea');
    formInputs.forEach(input => {
        // Carregar rascunho salvo
        const savedValue = localStorage.getItem(`draft_${input.name}`);
        if (savedValue && !input.value) {
            input.value = savedValue;
        }
        
        // Guardar mudanças
        input.addEventListener('input', function() {
            localStorage.setItem(`draft_${this.name}`, this.value);
        });
    });
    
    // Limpar rascunhos quando formulário é submetido com sucesso
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            const inputs = form.querySelectorAll('input, select, textarea');
            inputs.forEach(input => {
                localStorage.removeItem(`draft_${input.name}`);
            });
        });
    });
});

// Função para calcular custos de protótipo em tempo real
function calculatePrototypeCost(prototypeId) {
    // Esta função seria expandida para calcular custos dinamicamente
    console.log('Calculando custo do protótipo:', prototypeId);
}

// Função para verificar disponibilidade de stock
function checkStockAvailability(componentId, quantity) {
    // Esta função verificaria se há stock suficiente
    console.log('Verificando stock para componente:', componentId, 'quantidade:', quantity);
}
