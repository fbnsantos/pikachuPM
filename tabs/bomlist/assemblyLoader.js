document.addEventListener('DOMContentLoaded', function() {
    const assemblyTypeSelection = document.getElementById('assembly-type-selection');
    
    if (assemblyTypeSelection) {    
        // Function to hide all fields and clear their values
        function hideAndClearAllFields() {  
            // Hide all fields
            document.getElementById('field-component-father').style.display = 'none';
            document.getElementById('field-component-child').style.display = 'none';
            document.getElementById('field-component-quantity').style.display = 'none';
            document.getElementById('field-assembly-father').style.display = 'none';
            document.getElementById('field-assembly-child').style.display = 'none';
            document.getElementById('field-assembly-quantity').style.display = 'none';
            
            // Clear field values
            const componentFather = document.querySelector('[name="component_father_id"]');
            const componentChild = document.querySelector('[name="component_child_id"]');
            const componentQuantity = document.querySelector('[name="component_quantity"]');
            const assemblyFather = document.querySelector('[name="assembly_father_id"]');
            const assemblyChild = document.querySelector('[name="assembly_child_id"]');
            const assemblyQuantity = document.querySelector('[name="assembly_quantity"]');
            
            if (componentFather) componentFather.value = '';
            if (componentChild) componentChild.value = '';
            if (componentQuantity) componentQuantity.value = '1';
            if (assemblyFather) assemblyFather.value = '';
            if (assemblyChild) assemblyChild.value = '';
            if (assemblyQuantity) assemblyQuantity.value = '1';

            // Remover obrigatoriedade dos campos
            document.querySelector('[name="component_father_id"]').removeAttribute('required');
            document.querySelector('[name="component_child_id"]').removeAttribute('required');
            document.querySelector('[name="component_quantity"]').removeAttribute('required');
            document.querySelector('[name="assembly_father_id"]').removeAttribute('required');
            document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
            document.querySelector('[name="assembly_quantity"]').removeAttribute('required');

        }
        
        // Initialize by showing component-component fields (default)
        hideAndClearAllFields();

        assemblyTypeSelection.addEventListener('change', function(event) {
            const selectedType = event.target.value;
            
            // Hide all fields and clear values first
            hideAndClearAllFields();

            // Hide all fields and clear values first
            hideAndClearAllFields();

            // Show relevant fields based on selection
            switch (selectedType) {
                case 'component_component':
                    // Show component-component fields
                    document.getElementById('field-component-father').style.display = 'block';
                    document.getElementById('field-component-child').style.display = 'block';
                    document.getElementById('field-component-quantity').style.display = 'block';

                    // Set required attributes for component fields
                    document.querySelector('[name="component_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_child_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_quantity"]').removeAttribute('required');
                    break;
                    
                case 'component_assembly':
                    document.getElementById('field-component-father').style.display = 'block';
                    document.getElementById('field-component-child').style.display = 'none';
                    document.getElementById('field-component-quantity').style.display = 'block';
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-quantity').style.display = 'block';

                     // Set required attributes for component-assembly fields
                    document.querySelector('[name="component_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_child_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_quantity"]').removeAttribute('required');

                    break;
                case 'assembly_assembly':
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-child').style.display = 'block';
                    document.getElementById('field-assembly-quantity').style.display = 'block';
        // Set required attributes for assembly-assembly fields
                    document.querySelector('[name="assembly_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_child_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_father_id"]').removeAttribute('required');
                    document.querySelector('[name="component_child_id"]').removeAttribute('required');
                    document.querySelector('[name="component_quantity"]').removeAttribute('required');

                    break;
            }
        });

         // Função para exportar dados para CSV
        window.exportToCSV = function(type) {
            const data = [];
            let headers = [];
            
            switch(type) {
                case 'components':
                    headers = ['ID', 'Denominação', 'Tipo', 'Fabricante', 'Fornecedor', 'Preço', 'Stock'];
                    components.forEach(component => {
                        data.push([
                            component.Component_ID,
                            component.Denomination,
                            component.General_Type,
                            component.Manufacturer_Name,
                            component.Supplier_Name,
                            component.Price ?? 0,
                            component.Stock_Quantity
                        ]);
                    });
                    break;
                    
                case 'prototypes':
                    headers = ['ID', 'Nome', 'Versão', 'Estado', 'Data Criação'];
                    prototypes.forEach(prototype => {
                        data.push([
                            prototype.Prototype_ID,
                            prototype.Name,
                            prototype.Version,
                            prototype.Status,
                            new Date(prototype.Created_Date).toLocaleDateString('pt-PT')
                        ]);
                    });
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
            if (!selectedPrototype) {
                alert('Por favor, selecione um protótipo na aba de Montagem primeiro.');
                return;
            }
            
            // Redirecionar para a página de relatório
            window.open(`bom_report.php?prototype_id=${selectedPrototype}`, '_blank');
        };
    }
});
    