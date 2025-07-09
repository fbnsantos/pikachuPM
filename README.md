**Pikachu - blkpd-dev branch**

*Base de Dados:*
![Database Schema](db.png)


*TODO*

- Fazer constraints no SQL para alterar entre assemblies atómicas, compostas, ou mixed
- Alterar atributos novos em todas as queries no php
- Cada assembly devia ter um nome (ou ID) específico em vez de ficar com o nome do protótipo
---

*DONE*

- tab=bomlist alterado para tab=bomlist/bomlist
- Atributos separados/alterados para a montagem:
    - Child_ID -> Component_Child_ID | Assembly_Child_ID 
    - Father_ID -> Component_Father_ID | Assembly_Father_ID
    - Quantity -> Component_Quantity | Assembly_Quantity
    - Level_Depth -> Assembly_Level_Depth
