# Pikachu - blkpd-dev branch 

**Base de Dados:**
![Database Schema](db.png)


**TODO**

- Fazer constraints no SQL para alterar entre assemblies atómicas, compostas, ou mixed
- Alterar atributos novos em todas as queries no php
- Cada assembly devia ter um nome (ou ID) específico em vez de ficar com o nome do protótipo
- Falta alterar os IDs que aparecem nos separadores Montagem-Pai e Montagem-Filho, para aparecer a designação da montagem em vez do ID
- Level-Depth não está a funcionar -> clarificar o que significa
- Clarificar o que significa Nível Raíz
- Tirar a dúvida se se precisa de duas assemblies para fazer uma nova
- Falta implementar a função com a query para ir buscar as designações das duas sub-montagens a ser usadas para fazer uma montagem nova
---

**DONE**

- tab=bomlist alterado para tab=bomlist/bomlist
- Atributos separados/alterados para a montagem:
    - Child_ID -> Component_Child_ID | Assembly_Child_ID 
    - Father_ID -> Component_Father_ID | Assembly_Father_ID
    - Quantity -> Component_Quantity | Assembly_Quantity
    - Level_Depth -> Assembly_Level_Depth
- Alterei a interface para ao selecionar uma Montagem, aparecer *"Protótipo Versão - Designação da Montagem"*
- Acrescentei um Tab com a designação da montagem
