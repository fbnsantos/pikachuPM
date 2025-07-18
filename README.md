# Pikachu - blkpd-dev branch 

**Base de Dados:**
![Database Schema](db.png)


**TODO**

- Cada assembly devia ter um nome (ou ID) específico em vez de ficar com o nome do protótipo <--
- Level-Depth não está a funcionar -> pretende-se que seja feito pelo sistema, retirar o nível da tabela atual, e somar ou subtrair conforme necessário
- Falta acrescentar as assemblys ao "Resumo da BOM"
- Tornar as informações de cada assembly (e de cada protótipo) numa árvore -> ramos, sub-ramos, etc
- Corrigir a árvore para ir buscar componentes em vez de só sub-assemblies
- Árvore está a ser gerada ao contrário
- Procurar o assembly respectivo do protótipo para pegar nesse Assembly_ID e assim já funcionar com a tabela necessária



---

**DONE**

- tab=bomlist alterado para tab=bomlist/bomlist
- Atributos separados/alterados para a montagem:
    - Child_ID -> Component_Child_ID | Assembly_Child_ID 
    - Father_ID -> Component_Father_ID | Assembly_Father_ID
    - Quantity -> Component_Quantity | Assembly_Quantity
    - Level_Depth -> Assembly_Level_Depth
        - **Atributos também já alterados em todas as queries necessárias**

- Alterei a interface para ao selecionar uma Montagem, aparecer *"Protótipo Versão - Designação da Montagem"*
- Acrescentei um Tab com a designação da montagem
- Alterei as componentes pai e filho e as montagens pai e filho para não ser obrigatório -> depois acrescentar constraints para garantir que pelo menos dois estão inseridos
- Alterei a função getAssemblies() para também ter a designação das sub-assemblies presentes lá (se houver)
- Agora aparece a respectiva designação da montagem pai e filho em vez dos respectivos IDs na Estrutura de Montagem
- Mudei o nome da variável "Father_Name" para "Component_Father_Designation" (mesmo para a variável do componente-filho) -> query no getters.php também já está em conformidade
- Acrescentei botões para escolher o tipo de assembly -> Agora já muda os campos visíveis corretamente baseado no botão escolhido
- Acrescentei redirecionamento para evitar reenvio do formulário -> para não criar assemblies sozinho ao dar refresh à página (feito para todos os assemblies) -> falta fazer para components, prototypes, etc
- Já tem o espaço para dar display à árvore dos assemblies de cada protótipo -> back-end não funciona aínda, não consegue mostrar o conteúdo, pode ser problema da função de busca, ou de render
- Código adaptado para encaixar no refactor
- Implementado corretamente o nível da assembly -> quando se acrescenta uma nova assembly, escolhe o nivel mais alto entre as duas, e soma 1
- Já é possível acrescentar um protótipo a uma assembly nova -> Identifica o ID da maior assembly (maior nível) dentro desse protótipo, e dá essa assembly como assembly filho ou pai, consoante o caso
