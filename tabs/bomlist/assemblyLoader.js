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
        }
        
        // Initialize by showing component-component fields (default)
        hideAndClearAllFields();
        document.getElementById('field-component-father').style.display = 'block';
        document.getElementById('field-component-child').style.display = 'block';
        document.getElementById('field-component-quantity').style.display = 'block';

        assemblyTypeSelection.addEventListener('change', function(event) {
            const selectedType = event.target.value;
            
            // Hide all fields and clear values first
            hideAndClearAllFields();

            // Hide all fields and clear values first
            hideAndClearAllFields();

            // Show relevant fields based on selection
            switch (selectedType) {
                case 'component_component':
                    document.getElementById('field-component-father').style.display = 'block';
                    document.getElementById('field-component-child').style.display = 'block';
                    document.getElementById('field-component-quantity').style.display = 'block';
                    break;
                case 'component_assembly':
                    document.getElementById('field-component-father').style.display = 'none';
                    document.getElementById('field-component-child').style.display = 'block';
                    document.getElementById('field-component-quantity').style.display = 'block';
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-quantity').style.display = 'block';
                    break;
                case 'assembly_assembly':
                    document.getElementById('field-assembly-father').style.display = 'block';
                    document.getElementById('field-assembly-child').style.display = 'block';
                    document.getElementById('field-assembly-quantity').style.display = 'block';
                    break;
            }
        });
    }
});
    