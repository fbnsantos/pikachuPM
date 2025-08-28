function showAssociationFields(value) {
    const associationFields = document.getElementById('association-fields');
    associationFields.style.display = value ? 'block' : 'none';
}

function showAssemblyFields() {
    document.getElementById('remove-association-fields').style.display = 'none';
    document.getElementById('assembly-association-fields').style.display = 'block';
    document.getElementById('component-association-fields').style.display = 'none';
}

function showComponentFields() {
    document.getElementById('remove-association-fields').style.display = 'none';
    document.getElementById('assembly-association-fields').style.display = 'none';
    document.getElementById('component-association-fields').style.display = 'block';
}

// Função que carrega os dados de associações de assemblies via AJAX


function loadAllAssociations() {
    return fetch('tabs/bomlist/assemblyAssociations.php')
        .then(response => response.json())
        .catch(error => {
            console.error("Erro ao carregar as associações:", error);
            return { components: [], assemblies: [] };
        });
}





// Função para mostrar associações de uma assembly (filtrando os registros conforme o assemblyId)


// Função para mostrar associações de uma assembly (filtrando os registros conforme o assemblyId)


function showAssemblyAssociations(assemblyId) {
    if (!assemblyId) return;

    // Exibe spinner enquanto carrega
    const modalBody = document.getElementById('associatedAssemblyContent');

    modalBody.innerHTML = `
        <div class="text-center">
            <div class="spinner-border text-primary" role="status">
                <span class="visually-hidden">Carregando...</span>
            </div>
        </div>`;
    loadAllAssociations().then(data => {
        // Filtra os registros que pertencem ao assembly selecionado
        const compAssoc = data.components.filter(item => item.Assembly_ID == assemblyId);
        const assemAssoc = data.assemblies.filter(item => item.Parent_Assembly_ID == assemblyId);
        // Monta o HTML para os componentes associados
        let compHtml = compAssoc.length
            ? `<table class="table table-bordered table-fixed">
                   <thead>
                       <tr>
                           <th class="col-id">ID</th>
                           <th class="col-designacao">Designação</th>
                           <th class="col-quantidade">Quantidade</th>
                       </tr>
                   </thead>
                   <tbody>`
            : '<div class="alert alert-info">Nenhum componente associado.</div>';


        compAssoc.forEach(item => {
            compHtml += `<tr>
                            <td class="col-id">${item.Component_ID}</td>
                            <td class="col-designacao">${item.Denomination || '-'}</td>
                            <td class="col-quantidade">${item.Quantity}</td>
                         </tr>`;
        });


        if (compAssoc.length) compHtml += '</tbody></table>';
        // Monta o HTML para as assemblies associadas
        let assemHtml = assemAssoc.length
            ? `<table class="table table-bordered table-fixed">
                   <thead>
                       <tr>
                           <th class="col-id">ID</th>
                           <th class="col-designacao">Designação</th>
                           <th class="col-quantidade">Quantidade</th>
                       </tr>
                   </thead>
                   <tbody>`
            : '<div class="alert alert-info">Nenhuma assembly associada.</div>';


        assemAssoc.forEach(item => {
            assemHtml += `<tr>
                            <td class="col-id">${item.Child_Assembly_ID}</td>
                            <td class="col-designacao">${item.Assembly_Designation || '-'}</td>
                            <td class="col-quantidade">${item.Quantity}</td>
                         </tr>`;
        });


        if(assemAssoc.length) assemHtml += '</tbody></table>';
        // Junta as seções e atualiza o modal
        const html = `<h6>Componentes Associados</h6>${compHtml}<hr>
                      <h6>Assemblies Associadas</h6>${assemHtml}`;
        modalBody.innerHTML = html;
        const modal = new bootstrap.Modal(document.getElementById('associatedAssemblyModal'));
        modal.show();
    });
}



document.addEventListener('DOMContentLoaded', function() {
    const assemblyTypeSelection = document.getElementById('assembly-type-selection');
    
    // Referência dos componentes
    const customFatherRefInput = document.querySelector('[name="component_father_custom_ref"]');
    const customChildRefInput = document.querySelector('[name="component_child_custom_ref"]');

    // ### custom ref + botão para Assembly ###
    const customAssemblyRefInput = document.querySelector('[name="assembly_father_custom_ref"]');
    const assemblySelect         = document.getElementById('assembly_name');
    const assemblyDetailsBtn     = document.getElementById('assemblyDetailsBtn');

    const assocRef   = document.getElementById('assembly_ref_assoc');
    const assocSel   = document.getElementById('associated_assembly');
    const assocBtn   = document.getElementById('assemblyDetailsBtnAssoc');

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

    // Para a referência da assembly
    if (customAssemblyRefInput && assemblySelect && typeof assemblies !== 'undefined') {
        // ao digitar na referência, tenta casar com Assembly_Reference e atualizar o select
        customAssemblyRefInput.addEventListener('input', function() {
        const v = this.value.trim();
        const found = assemblies.find(a=>a.Assembly_Reference===v);
        if (found) {
            assemblySelect.value      = found.Assembly_ID;
            assemblyDetailsBtn.disabled = false;
            // ─── aqui ───
            showAssociationFields(found.Assembly_ID);
            // ────────────
        } else {
            assemblySelect.value        = '';
            assemblyDetailsBtn.disabled = true;
        }
    });

        // ao mudar o select, atualiza o input e o estado do botão
        assemblySelect.addEventListener('change', function() {
            const sel = this.value;
            if (sel) {
            const f = assemblies.find(a=>a.Assembly_ID==sel);
            customAssemblyRefInput.value = f ? f.Assembly_Reference : '';
            assemblyDetailsBtn.disabled = false;
            // ─── e também aqui ───
            showAssociationFields(sel);
            // ──────────────────────
            } else {
            customAssemblyRefInput.value = '';
            assemblyDetailsBtn.disabled = true;
            }
        });
    }
    // quando clicares em “Ver Detalhes” chama o teu modal
    if (assemblyDetailsBtn) {
        assemblyDetailsBtn.addEventListener('click', function() {
            const id = assemblySelect.value;
            if (id) showAssemblyDetails(id);
        });
    }

      if (assocRef && assocSel && assocBtn && window.assemblies) {
        assocRef.addEventListener('input', ()=>{
        const v = assocRef.value.trim();
        const found = assemblies.find(a=>a.Assembly_Reference===v);
        assocSel.value    = found? found.Assembly_ID : '';
        assocBtn.disabled = !found;
        });
        assocSel.addEventListener('change', ()=>{
        const f = assemblies.find(a=>a.Assembly_ID==assocSel.value);
        assocRef.value    = f? f.Assembly_Reference : '';
        assocBtn.disabled = !f;
        });
        assocBtn.addEventListener('click', ()=>{
        if (assocSel.value) showAssemblyDetails(assocSel.value);
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
                        <th>Referência</th>
                        <td>${selectedComponent.Reference || '-'}</td>
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
    /**
     * Exibe num modal os detalhes da assembly selecionada.
     * Depende de haver um array global `assemblies` com chaves:
     *   Assembly_ID, Assembly_Designation, Assembly_Reference,
     *   Assembly_Level, Price, Notes, Prototype_Name, Prototype_Version
     */
    function showAssemblyDetails(assemblyId) {
        if (!assemblyId) return;
        const selected = assemblies.find(a => a.Assembly_ID == assemblyId);
        if (!selected) return;

        // Prepara o HTML
        let html = `
        <table class="table table-bordered">
            <tr><th>ID</th><td>${selected.Assembly_ID}</td></tr>
            <tr><th>Designação</th><td>${selected.Assembly_Designation}</td></tr>
            <tr><th>Referência</th><td>${selected.Assembly_Reference}</td></tr>
            <tr><th>Protótipo</th>
            <td>${selected.Prototype_Name} v${selected.Prototype_Version}</td>
            </tr>
            <tr><th>Preço</th>
            <td>${selected.Price ? selected.Price.toFixed(2) + ' €' : '-'}</td>
            </tr>
            <tr><th>Notas</th><td>${selected.Notes || '-'}</td></tr>
        </table>
        `;

        // Aqui sim atribuis o html ao modal
        const modalContent = document.getElementById('assemblyDetailsContent');
        modalContent.innerHTML = html;

        // E finalmente abres o modal
        const modal = new bootstrap.Modal(
        document.getElementById('assemblyDetailsModal')
        );
        modal.show();
}

    function showRemoveFields(){
        const mainDisplay = document.getElementById('remove-association-fields');
        mainDisplay.style.display = 'block';
        document.getElementById('assembly-association-fields').style.display = 'none';
        document.getElementById('component-association-fields').style.display = 'none';
    }

    function showRemoveOptions(value){
        if (value === 'component'){
            document.getElementById('remove_assembly_div').style.display = 'none';
            const mainDisplay = document.getElementById('remove_component_div');
            const assemblyID = document.getElementById('assembly_name').value;
            // get the list of components that are connected to this assembly ID
            fetch('tabs/bomlist/getters.php',{
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // enviar assemblyID como query parameter
                body: JSON.stringify({
                assemblyID : assemblyID,
                action: "getAssociatedComps" 
                })
            })
            .then(response => response.json())
            .then(data => {
                let options = '<select class="form-select" name="remove_component_id"><option value="">Selecionar...</option>';
                data.forEach(item => {
                        options += `<option value="${item.Component_ID}">${item.Denomination} ( ${item.Reference})</option>`;
                });
                options += '</select>';
                mainDisplay.innerHTML = options;
            });

            document.getElementById('remove_component_div').style.display = 'block';
        } else if (value === 'assembly'){
            document.getElementById('remove_component_div').style.display = 'none';
            mainDisplay = document.getElementById('remove_assembly_div');
            const assemblyID = document.getElementById('assembly_name').value;
            // get the list of assemblies that are connected to this assembly ID
            fetch('tabs/bomlist/getters.php',{
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                // enviar assemblyID como query parameter
                body: JSON.stringify({
                assemblyID : assemblyID,
                action: "getAssociatedAssemblies"
                })
            })
            .then(response => response.json())
            .then(data => {
                let options = '<select class="form-select" name= "remove_assembly_id"><option value="">Selecionar...</option>';
                data.forEach(item => {
                        options += `<option value="${item.Assembly_ID}">${item.Assembly_Designation} ( ${item.Assembly_Reference})</option>`;
                });
                options += '</select>';
                mainDisplay.innerHTML = options;
            });
            document.getElementById('remove_assembly_div').style.display = 'block';
        } else {
            document.getElementById('remove_component-div').style.display = 'none';
            document.getElementById('remove_assembly_div').style.display = 'none';
        }
    }