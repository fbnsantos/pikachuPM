
function showAssociationFields(value) {
    const associationFields = document.getElementById('association-fields');
    associationFields.style.display = value ? 'block' : 'none';
}

function showAssemblyFields() {
    document.getElementById('assembly-association-fields').style.display = 'block';
    document.getElementById('component-association-fields').style.display = 'none';
}

function showComponentFields() {
    document.getElementById('assembly-association-fields').style.display = 'none';
    document.getElementById('component-association-fields').style.display = 'block';
}



document.addEventListener('DOMContentLoaded', function() {
    const assemblyTypeSelection = document.getElementById('assembly-type-selection');
    
    // Referência dos componentes
    const customFatherRefInput = document.querySelector('[name="component_father_custom_ref"]');
    const customChildRefInput = document.querySelector('[name="component_child_custom_ref"]');

    // Para componente pai
    const componentFatherSelect = document.getElementById('component_father_id');
    const componentDetailsBtn = document.getElementById('componentDetailsBtn');
    
    // Para componente filho
    const componentChildSelect = document.getElementById('component_child_id');
    const componentChildDetailsBtn = document.getElementById('componentChildDetailsBtn');
    
    // # Para mostrar os botões de detalhes ao escrever a referência
    if (customFatherRefInput && componentFatherSelect && typeof components !== 'undefined') {
        customFatherRefInput.addEventListener('input', function() {
            const val = customFatherRefInput.value.trim();
            if (val !== '') {
                // Procura o componente cujo campo Reference seja igual ao valor inserido
                const found = components.find(c => c.Reference === val);
                if(found) {
                    // Atualiza o select para o componente encontrado
                    componentFatherSelect.value = found.Component_ID;
                    componentDetailsBtn.disabled = false; // Habilita o botão de detalhes
                } else {
                    // Caso não encontre, opcionalmente limpa a seleção
                    componentFatherSelect.value = '';
                    componentChildDetailsBtn.disabled = true; // Desabilita o botão de detalhes
                }
            } else {
                // Se o campo estiver vazio, limpar a seleção
                componentFatherSelect.value = '';
                componentDetailsBtn.disabled = true; // Desabilita o botão de detalhes
            }
        });
    }
    if (customChildRefInput && componentChildSelect && typeof components !== 'undefined') {
        customChildRefInput.addEventListener('input', function() {
            const val = customChildRefInput.value.trim();
            if (val !== '') {
                // Procura o componente cujo campo Reference seja igual ao valor inserido
                const found = components.find(c => c.Reference === val);
                if(found) {
                    // Atualiza o select para o componente encontrado
                    componentChildSelect.value = found.Component_ID;
                    componentChildDetailsBtn.disabled = false; // Habilita o botão de detalhes
                } else {
                    // Caso não encontre, opcionalmente limpa a seleção
                    componentChildSelect.value = '';
                    componentChildDetailsBtn.disabled = true; // Desabilita o botão de detalhes
                }
            } else {
                // Se o campo estiver vazio, limpar a seleção
                componentChildSelect.value = '';
                componentChildDetailsBtn.disabled = true; // Desabilita o botão de detalhes
            }
        });
    } // #

    // Para componente pai: quando o select mudar, atualiza o input da referência manual
    if (componentFatherSelect && customFatherRefInput) {
        componentFatherSelect.addEventListener('change', function() {
            const selectedId = componentFatherSelect.value;
            if (selectedId) {
                const found = components.find(c => c.Component_ID == selectedId);
                if (found) {
                    customFatherRefInput.value = found.Reference || '';
                }
            } else {
                customFatherRefInput.value = '';
            }
        });
    }

    // Para componente filho: quando o select mudar, atualiza o input da referência manual
    if (componentChildSelect && customChildRefInput) {
        componentChildSelect.addEventListener('change', function() {
            const selectedId = componentChildSelect.value;
            if (selectedId) {
                const found = components.find(c => c.Component_ID == selectedId);
                if (found) {
                    customChildRefInput.value = found.Reference || '';
                }
            } else {
                customChildRefInput.value = '';
            }
        });
    }

        // Função para mostrar detalhes do componente
    function showComponentDetails(componentId) {
        if (!componentId) return;
        
        // Encontrar o componente selecionado nos dados
        const selectedComponent = components.find(c => c.Component_ID == componentId);
        
        if (selectedComponent) {
            // Atualizar o conteúdo do modal com os detalhes do componente
            const modalContent = document.getElementById('componentDetailsContent');
            
            // Criar tabela HTML com os detalhes do componente
            let html = `
                <table class="table table-bordered">
                    <tr>
                        <th>ID</th>
                        <td>${selectedComponent.Component_ID}</td>
                    </tr>
                    <tr>
                        <th>Denominação</th>
                        <td>${selectedComponent.Denomination}</td>
                    </tr>
                    <tr>
                        <th>Tipo</th>
                        <td>${selectedComponent.General_Type || '-'}</td>
                    </tr>
                    <tr>
                        <th>Fabricante</th>
                        <td>${selectedComponent.Manufacturer_Name || '-'}</td>
                    </tr>
                    <tr>
                        <th>Fornecedor</th>
                        <td>${selectedComponent.Supplier_Name || '-'}</td>
                    </tr>
                    <tr>
                        <th>Preço</th>
                        <td>${selectedComponent.Price ? selectedComponent.Price + ' €' : '-'}</td>
                    </tr>
                    <tr>
                        <th>Stock</th>
                        <td>${selectedComponent.Stock_Quantity}</td>
                    </tr>
                    <tr>
                        <th>Notas/Descrição</th>
                        <td>${selectedComponent.Notes_Description || '-'}</td>
                    </tr>
                </table>
            `;
            
            modalContent.innerHTML = html;
            
            // Abrir o modal
            const modal = new bootstrap.Modal(document.getElementById('componentDetailsModal'));
            modal.show();
        }
    }
    
    // Configurar eventos para o componente pai
    if (componentFatherSelect && componentDetailsBtn) {
        componentFatherSelect.addEventListener('change', function() {
            componentDetailsBtn.disabled = !componentFatherSelect.value;
        });
        
        componentDetailsBtn.addEventListener('click', function() {
            const componentId = componentFatherSelect.value;
            showComponentDetails(componentId);
        });
    }
    
    // Configurar eventos para o componente filho
    if (componentChildSelect && componentChildDetailsBtn) {
        componentChildSelect.addEventListener('change', function() {
            componentChildDetailsBtn.disabled = !componentChildSelect.value;
        });
        
        componentChildDetailsBtn.addEventListener('click', function() {
            const componentId = componentChildSelect.value;
            showComponentDetails(componentId);
        });
    }

    window.showManufacturerComponents = function(manufacturerId) {
        if (!manufacturerId) return;
        
        // Filtrar os componentes pelo Manufacturer_ID
        const filteredComponents = components.filter(c => c.Manufacturer_ID == manufacturerId);
        
        // Construir HTML com os componentes filtrados
        let html = '';
        if (filteredComponents.length) {
            html += `
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Denominação</th>
                            <th>Referência</th>
                            <th>Preço</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            filteredComponents.forEach(comp => {
                html += `
                    <tr>
                        <td>${comp.Component_ID}</td>
                        <td>${comp.Denomination}</td>
                        <td>${comp.Reference || '-'}</td>
                        <td>${comp.Price ? comp.Price + ' €' : '-'}</td>
                    </tr>
                `;
            });
            html += `
                    </tbody>
                </table>
            `;
        } else {
            html = '<div class="alert alert-info">Nenhum componente encontrado para este fabricante.</div>';
        }
        
        // Atualizar o corpo da modal e exibi-la
        const modalContent = document.getElementById('associatedComponentsContent');
        modalContent.innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('associatedComponentsModal'));
        modal.show();
    };

    window.showSupplierComponents = function(supplierId) {
        if (!supplierId) return;
        
        // Filtrar os componentes pelo Supplier_ID
        const filteredComponents = components.filter(c => c.Supplier_ID == supplierId);
        
        // Construir HTML com os componentes filtrados
        let html = '';
        if (filteredComponents.length) {
            html += `
                <table class="table table-bordered">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Denominação</th>
                            <th>Referência</th>
                            <th>Preço</th>
                        </tr>
                    </thead>
                    <tbody>
            `;
            filteredComponents.forEach(comp => {
                html += `
                        <tr>
                            <td>${comp.Component_ID}</td>
                            <td>${comp.Denomination}</td>
                            <td>${comp.Reference || '-'}</td>
                            <td>${comp.Price ? comp.Price + ' €' : '-'}</td>
                        </tr>
                `;
            });
            html += `
                    </tbody>
                </table>
            `;
        } else {
            html = '<div class="alert alert-info">Nenhum componente encontrado para este fornecedor.</div>';
        }
        
        // Atualizar o corpo da modal correspondente e exibi-la
        const modalContent = document.getElementById('associatedSupplierComponentsContent');
        modalContent.innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('associatedSupplierComponentsModal'));
        modal.show();
    };

    


    if (assemblyTypeSelection) {    
        // Function to hide all fields and clear their values
        function hideAndClearAllFields() {  
            // Hide all fields
            document.getElementById('field-component-father').style.display = 'none';
            document.getElementById('field-component-father-quantity').style.display = 'none';
            document.getElementById('field-component-child').style.display = 'none';
            document.getElementById('field-component-child-quantity').style.display = 'none';
            document.getElementById('field-assembly-father').style.display = 'none';
            document.getElementById('field-assembly-father-quantity').style.display = 'none';
            document.getElementById('field-assembly-child').style.display = 'none';
            document.getElementById('field-assembly-child-quantity').style.display = 'none';

            // Clear field values
            const componentFather = document.querySelector('[name="component_father_id"]');
            const componentFatherQuantity = document.querySelector('[name="component_father_quantity"]');
            const componentChild = document.querySelector('[name="component_child_id"]');
            const componentChildQuantity = document.querySelector('[name="component_child_quantity"]');
            const assemblyFather = document.querySelector('[name="assembly_father_id"]');
            const assemblyFatherQuantity = document.querySelector('[name="assembly_father_quantity"]');
            const assemblyChild = document.querySelector('[name="assembly_child_id"]');
            const assemblyChildQuantity = document.querySelector('[name="assembly_child_quantity"]');
            
            if (componentFather) componentFather.value = '';
            if (componentFatherQuantity) componentFatherQuantity.value = '';
            if (componentChild) componentChild.value = '';
            if (componentChildQuantity) componentChildQuantity.value = '';
            if (assemblyFather) assemblyFather.value = '';
            if (assemblyFatherQuantity) assemblyFatherQuantity.value = '';
            if (assemblyChild) assemblyChild.value = '';
            if (assemblyChildQuantity) assemblyChildQuantity.value = '';

            // Remover obrigatoriedade dos campos
            document.querySelector('[name="component_father_id"]').removeAttribute('required');
            document.querySelector('[name="component_father_quantity"]').removeAttribute('required');
            document.querySelector('[name="component_child_id"]').removeAttribute('required');
            document.querySelector('[name="component_child_quantity"]').removeAttribute('required');
            document.querySelector('[name="assembly_father_id"]').removeAttribute('required');
            document.querySelector('[name="assembly_father_quantity"]').removeAttribute('required');
            document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
            document.querySelector('[name="assembly_child_quantity"]').removeAttribute('required');
        }


        // Atribuir eventos aos botões
        document.addEventListener('DOMContentLoaded', function() {
            const btnComponent = document.getElementById('btn-add-component');
            const btnAssembly = document.getElementById('btn-add-assembly');
            
            if (btnComponent) {
                btnComponent.addEventListener('click', function() {
                    // Por exemplo, para "Adicionar Componente" usamos o tipo component_component
                    showAssemblyFields('component_component');
                });
            }
            if (btnAssembly) {
                btnAssembly.addEventListener('click', function() {
                    // Se desejar "Adicionar Assembly" use outro valor, aqui 'component_assembly' ou 'assembly_assembly'
                    showAssemblyFields('component_assembly');
                });
            }
        });
        
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
                    document.getElementById('field-component-father-quantity').style.display = 'block';
                    document.getElementById('field-component-child').style.display = 'block';
                    document.getElementById('field-component-child-quantity').style.display = 'block';

                    // Set required attributes for component fields
                    document.querySelector('[name="component_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_father_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_child_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_child_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_father_quantity"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_quantity"]').removeAttribute('required');

                    //debug to console - required
                    console.log('Component Father Required:', document.querySelector('[name="component_father_id"]').hasAttribute('required'));
                    console.log('Component Child Required:', document.querySelector('[name="component_child_id"]').hasAttribute('required'));
                    console.log('Assembly Father Required:', document.querySelector('[name="assembly_father_id"]').hasAttribute('required'));
                    console.log('Assembly Child Required:', document.querySelector('[name="assembly_child_id"]').hasAttribute('required'));

                    break;
                                    
                case 'component_assembly':
                    document.getElementById('field-component-father').style.display = 'block';
                    document.getElementById('field-component-father-quantity').style.display = 'block';
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-father-quantity').style.display = 'block';
                    document.getElementById('field-component-child').style.display = 'none';
                    document.getElementById('field-component-child-quantity').style.display = 'none';
                    document.getElementById('field-assembly-child').style.display = 'none';
                    document.getElementById('field-assembly-child-quantity').style.display = 'none';

                    

                     // Set required attributes for component-assembly fields
                    document.querySelector('[name="component_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_father_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_child_id"]').removeAttribute('required');
                    document.querySelector('[name="component_child_quantity"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_id"]').removeAttribute('required');
                    document.querySelector('[name="assembly_child_quantity"]').removeAttribute('required');


                    //debug to console - required
                    console.log('Component Father Required:', document.querySelector('[name="component_father_id"]').hasAttribute('required'));
                    console.log('Assembly Father Required:', document.querySelector('[name="assembly_father_id"]').hasAttribute('required'));
                    console.log('Component Child Required:', document.querySelector('[name="component_child_id"]').hasAttribute('required'));
                    console.log('Assembly Child Required:', document.querySelector('[name="assembly_child_id"]').hasAttribute('required'));

                    break;
                case 'assembly_assembly':
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-father-quantity').style.display = 'block';
                    document.getElementById('field-assembly-child').style.display = 'block';
                    document.getElementById('field-assembly-child-quantity').style.display = 'block';

                    // Set required attributes for assembly-assembly fields
                    document.querySelector('[name="assembly_father_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_father_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_child_id"]').setAttribute('required', 'required');
                    document.querySelector('[name="assembly_child_quantity"]').setAttribute('required', 'required');
                    document.querySelector('[name="component_father_id"]').removeAttribute('required');
                    document.querySelector('[name="component_father_quantity"]').removeAttribute('required');
                    document.querySelector('[name="component_child_id"]').removeAttribute('required');
                    document.querySelector('[name="component_child_quantity"]').removeAttribute('required');

                    //debug to console - required
                    console.log('Assembly Father Required:', document.querySelector('[name="assembly_father_id"]').hasAttribute('required'));
                    console.log('Assembly Child Required:', document.querySelector('[name="assembly_child_id"]').hasAttribute('required'));
                    console.log('Component Father Required:', document.querySelector('[ name="component_father_id"]').hasAttribute('required'));
                    console.log('Component Child Required:', document.querySelector('[name="component_child_id"]').hasAttribute('required'));

                    break;
            }
        });


    }
             // Função para exportar dados para CSV
        window.exportToCSV = function(type) {
            console.log('Exportando CSV para:', type);
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
                alert('Selecionar um protótipo na aba de Assembly primeiro.');
                return;
            }
            
            // Redirecionar para a página de relatório
            window.open(`bom_report.php?prototype_id=${selectedPrototype}`, '_blank');
        };

        const form = document.querySelector('form');
        if (form) {
            form.addEventListener('submit', function(e) {
                const manufacturer = document.querySelector('[name="manufacturer_id"]').value;
                const supplier = document.querySelector('[name="supplier_id"]').value;
                if (manufacturer === '' && supplier === '') {
                    alert('Selecionar um fabricante ou fornecedor.');
                    e.preventDefault();
                }
            });
        }
    });

    // --- AJAX helpers para criar fabricante/fornecedor sem perder o formulário principal ---
   async function submitFormAjaxElement(formEl, modalId, triggerBtn) {
    if (!formEl) return console.error('Form not found for AJAX submit');
    if (triggerBtn) triggerBtn.disabled = true;

    // fallback para um path absoluto caso form.action esteja vazio/relativo errado
    const fallbackUrl = '/tabs/bomlist/processor.php';
    // ler action de forma segura (property pode não ser string)
    let actionValue = '';
    if (typeof formEl.action === 'string') {
        actionValue = formEl.action;
    } else if (formEl.getAttribute) {
        actionValue = formEl.getAttribute('action') || '';
    }
    actionValue = String(actionValue || '').trim();
    let url = fallbackUrl;
    if (actionValue !== '') {
        try {
            // resolve relative URLs against current location
            url = new URL(actionValue, window.location.href).toString();
        } catch (e) {
            console.warn('Invalid form action, using fallback:', actionValue, e);
            url = fallbackUrl;
        }
    }
     console.log('AJAX submit URL ->', url);
 
    const fd = new FormData(formEl);
    try {
        const res = await fetch(url, {
            method: 'POST',
            body: fd,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        });

        console.log('AJAX response status:', res.status, 'content-type:', res.headers.get('content-type'));
        const text = await res.text();

        // Se o servidor devolveu HTML (começa por '<'), abortar e logar
        if (text.trim().startsWith('<')) {
            console.error('Resposta contém HTML — provavelmente o request foi para index.php em vez de processor.php. Response (preview):', text.slice(0,300));
            // mostrar erro ao utilizador
            const container = document.querySelector('.container-fluid') || document.body;
            const alert = document.createElement('div');
            alert.className = 'alert alert-danger alert-dismissible fade show';
            alert.role = 'alert';
            alert.innerHTML = 'Erro do servidor: resposta inesperada (HTML). Ver console / Network para detalhes.' +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            container.prepend(alert);
            throw new Error('Invalid HTML response');
        }

        let json = {};
        try { json = JSON.parse(text); } catch (e) { console.warn('JSON parse failed:', e, 'raw:', text); }

        // inserir novo option se veio created
        if (json.created) {
            const entity = json.created.entity;
            let sel = null;
            if (entity === 'manufacturers') sel = document.querySelector('select[name="manufacturer_id"]');
            if (entity === 'suppliers') sel = document.querySelector('select[name="supplier_id"]');
            if (sel) {
                const opt = document.createElement('option');
                opt.value = json.created.id;
                opt.textContent = json.created.denomination;
                opt.selected = true;
                sel.appendChild(opt);
            }
        }

        // mostrar mensagem (se houver)
        if (json.message) {
            const container = document.querySelector('.container-fluid') || document.body;
            const alert = document.createElement('div');
            alert.className = 'alert alert-success alert-dismissible fade show';
            alert.role = 'alert';
            alert.innerHTML = document.createTextNode(json.message).textContent +
                '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
            container.prepend(alert);
            setTimeout(()=>{ try{ bootstrap.Alert.getOrCreateInstance(alert).close(); }catch{} }, 4000);
        }

        // fechar modal
        if (modalId) {
            const modalEl = document.getElementById(modalId);
            if (modalEl) {
                const modalInstance = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
                modalInstance.hide();
            }
        }
        // limpar apenas o form do modal
        formEl.reset();
    } catch (err) {
        console.error('submitFormAjax error:', err);
    } finally {
        if (triggerBtn) triggerBtn.disabled = false;
    }
}

    // Tornar disponível globalmente se ainda usas onclick inline (opcional)
    window.submitFormAjax = function(formId, modalId) {
        const formEl = document.getElementById(formId) || document.querySelector(`#${modalId} form`) || document.querySelector('form');
        return submitFormAjaxElement(formEl, modalId, null);
    };

    // Ligar listeners aos botões com data-ajax-trigger
    document.querySelectorAll('button[data-ajax-trigger]').forEach(btn => {
        btn.addEventListener('click', function(e) {
            // encontra o form dentro do modal (ou o form mais próximo)
            const modalEl = btn.closest('.modal');
            const modalId = modalEl ? modalEl.id : null;
            // procura form: botão normalmente está dentro do form do modal
            let formEl = btn.closest('form');
            if (!formEl && modalEl) formEl = modalEl.querySelector('form');
            if (!formEl) {
                console.error('Nenhum form encontrado para o botão AJAX', btn);
                return;
            }
            submitFormAjaxElement(formEl, modalId, btn);
        });
    });

    // --- fim AJAX helpers ---
