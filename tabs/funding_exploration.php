<?php
// tabs/funding_exploration.php — Secção de Exploração de Oportunidades de Financiamento
// Incluído por leads.php — corre no mesmo contexto PHP (session, $pdo, etc.)

// ─── Criar tabelas ────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS funding_calls (
        id INT AUTO_INCREMENT PRIMARY KEY,
        titulo VARCHAR(300) NOT NULL,
        url TEXT,
        prioridade CHAR(1) DEFAULT 'C',
        prioridade_label VARCHAR(150),
        area TEXT,
        encaixe TEXT,
        tipo_trl VARCHAR(300),
        financiamento TEXT,
        projetos_apoiados VARCHAR(200),
        duracao VARCHAR(300),
        datas TEXT,
        elegibilidade TEXT,
        sucesso_anteriores VARCHAR(300),
        probabilidade VARCHAR(300),
        proximo_passo TEXT,
        fontes TEXT,
        estado ENUM('aberta','fechada','radar','arquivada') DEFAULT 'aberta',
        notas_internas TEXT,
        created_by INT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        lead_id INT NULL,
        INDEX idx_fc_prio (prioridade),
        INDEX idx_fc_estado (estado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // Adicionar lead_id se a tabela já existia sem a coluna
    try { $pdo->exec("ALTER TABLE funding_calls ADD COLUMN lead_id INT NULL"); } catch (Exception $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS funding_call_notes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        call_id INT NOT NULL,
        user_id INT NOT NULL,
        note_text TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (call_id) REFERENCES funding_calls(id) ON DELETE CASCADE,
        INDEX idx_fcn_call (call_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS funding_scout_keys (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        api_key VARCHAR(64) NOT NULL,
        scope VARCHAR(100) DEFAULT 'funding',
        created_by INT NOT NULL,
        last_used_at DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_key (api_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
} catch (Exception $e) {}

// ─── Seed: importar oportunidades do Excel se tabela estiver vazia ─────────────
$fcCount = $pdo->query("SELECT COUNT(*) FROM funding_calls")->fetchColumn();
if ($fcCount == 0) {
    try {
        $st = $pdo->prepare("INSERT INTO funding_calls (titulo, url, prioridade, prioridade_label, area, encaixe, tipo_trl, financiamento, projetos_apoiados, duracao, datas, elegibilidade, sucesso_anteriores, probabilidade, proximo_passo, fontes, estado, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute(['MPr-2026-7 — SIID Demonstradores', 'https://compete2030.gov.pt/avisos/siid-id-empresarial-demonstradores-individuais_mpr-2026-7/', 'A', 'A — Seleção principal — Aberto', 'Demonstração industrial de tecnologia resultante de I&D concluída, individualmente ou em copromoção.', 'Copromotor tecnológico: robótica, sensorização e validação em campo com líder empresarial.', 'Demonstrador Portugal 2030. TRL numérico não confirmado nas cláusulas revistas.', 'Dotação 12,5 M€. Investimento elegível mínimo 200 mil €. ENESII até 85%, exceto Lisboa até 40%, sob condições; taxa efetiva do INESC TEC por validar.', 'Número não publicado.', 'Máximo 18 meses. Extensão excecional justificada e aprovada; limite adicional não especificado [aviso, p.5, g)].', '14/07/2026–29/12/2026, 17h Lisboa. Decisão: 60 dias úteis, com ressalvas processuais; não é média histórica.', 'Liderança empresarial; INESC TEC como ENESII em copromoção, sujeito a validação. Resultados prévios e direitos de utilização necessários.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R preliminar anterior: 14/25, não validado com a equipa. Probabilidade não estimável.', 'Validar enquadramento empresarial e resultados prévios até 16/11. Email SENSEFiNiTY de 27/10 confirma preparação de candidatura; correspondência exata com este aviso por validar. Email de 10/11 às 18h56 refere alteração logística de reunião SENSEFiNiTY, não nova call nem mudança de prazo.', 'Verificação: 10/11/2026. Página e secções decisivas da republicação de 18/08 consultadas: p.5 duração; p.13 decisão. Parcial: anexos técnicos autónomos e validação jurídica pendentes. Email INESC consultado por pesquisa/resumo, não prova de elegibilidade.', 'aberta', 1]);
    $st->execute(['NOSTRADAMUS OC1', 'https://nostradamus-project.eu/open-call-1/', 'B', 'B — Seleção principal — Aberto', 'Aplicações digitais abertas para decisão agrícola, com foco em cereais e segurança alimentar.', 'IoT e observação da Terra para água, solo, pragas e aconselhamento; parceiro de I&D.', 'FSTP em montantes fixos. TRL inicial ≥4; janela 4–7.', '250 mil € na OC1; até 50 mil €/projeto; pedido cobre 100% dos custos incluindo indiretos. Pagamentos por marcos: 10/25/40/25%.', '5 projetos previstos.', '12 meses fixos. Prorrogação não confirmada.', 'Prazo 02/12/2026 às 17h CET = 16h Lisboa. Calendário de resultados/contrato por confirmar.', 'Máximo 2 entidades; líder empresarial. RTO/academia como segundo parceiro. Uma proposta por entidade; verificar conflitos de interesse.', 'Primeira OC1, sem 3 edições próprias comparáveis.', 'R anterior 13/25, não validado; probabilidade não estimável.', 'Confirmar líder empresarial, TRL e tópico até 12/11. Rever anexos técnicos, FAQ e contrato.', 'Verificação: 10/11/2026. Página e secções do guia consultadas: pp.7–13 financiamento/TRL/consórcio, p.12 elegibilidade. Parcial: Anexo 1.1, FAQ e contrato pendentes.', 'aberta', 1]);
    $st->execute(['HORIZON-NATURE-2027-02-05', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-NATURE-2027-02-05', 'B', 'B — Seleção principal — Aberto', 'Monitorização da biodiversidade com custos reduzidos.', 'Robôs, UAV, sensores e IA; parceria com ecólogos.', 'RIA; montantes fixos e TRL específicos por confirmar.', '16 M€ e 3 projetos indicativos na base anterior; orçamento por projeto ainda não reconfirmado.', '3 indicativos, por reconfirmar.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Abertura 13/10/2026; prazo 14/01/2027 segundo REA. Hora definitiva no portal por confirmar.', 'Consórcio europeu e especialistas em biodiversidade; regras e papel jurídico do INESC TEC por validar.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 14/25 não validado. Probabilidade não estimável.', 'Definir caso de uso e consórcio até 20/11.', 'Verificação: 10/11/2026. Calendário REA reconfirmado; prevalece sobre a data ANI divergente da base.', 'aberta', 1]);
    $st->execute(['HORIZON-NATURE-2027-02-02', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-NATURE-2027-02-02', 'B', 'B — Seleção principal — Aberto', 'Cocriação de soluções de restauro de ecossistemas em living labs.', 'Monitorização robótica e sensores ambientais; equipa de restauro e gestores de território.', 'RIA; montantes fixos e TRL específicos por confirmar.', '60 M€ na base anterior; apoio por projeto e taxa INESC TEC por confirmar.', 'Por confirmar.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Abertura 13/10/2026; prazo 14/01/2027 segundo REA. Hora definitiva no portal por confirmar.', 'Consórcio europeu e especialistas em biodiversidade; regras e papel jurídico do INESC TEC por validar.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 12/25 não validado. Probabilidade não estimável.', 'Identificar living lab e coordenação científica até 20/11.', 'Verificação: 10/11/2026. Calendário REA reconfirmado; prevalece sobre a data ANI divergente da base.', 'aberta', 1]);
    $st->execute(['HORIZON-NATURE-2027-02-01', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-NATURE-2027-02-01', 'B', 'B — Seleção principal — Aberto', 'Causas diretas e indiretas do declínio de invertebrados.', 'Visão/IA e observação automática de insetos; liderança em entomologia.', 'RIA; montantes fixos e TRL específicos por confirmar.', '15 M€ na base anterior; apoio por projeto por reconfirmar.', 'Por confirmar.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Abertura 13/10/2026; prazo 14/01/2027 segundo REA. Hora definitiva no portal por confirmar.', 'Consórcio europeu e especialistas em biodiversidade; regras e papel jurídico do INESC TEC por validar.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 11/25 não validado. Probabilidade não estimável.', 'Definir pergunta biológica e contribuição dos sensores até 17/11.', 'Verificação: 10/11/2026. Calendário REA reconfirmado; prevalece sobre a data ANI divergente da base.', 'aberta', 1]);
    $st->execute(['HORIZON-BRIDGING-2027-02-05', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-BRIDGING-2027-02-05', 'B', 'B — Seleção principal — Aberto', 'Valorização comercial de resultados promissores do Pilar II do Horizonte Europa.', 'Robótica e tecnologias digitais, mediante resultados e direitos de exploração elegíveis.', 'IA; montantes fixos e TRL por confirmar.', '32,5 M€ no concurso. Até 2,5 M€/projeto indicado na base anterior, por reconfirmar. Taxa efetiva INESC TEC por validar.', 'Não confirmado.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '20/10/2026–28/01/2027. Hora no portal por confirmar. Resultados maio; contrato julho 2027, indicativos.', 'Resultados prévios elegíveis do Pilar II e direitos de exploração são condição crítica.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 10/25 não validado. Probabilidade não estimável.', 'Auditar projetos anteriores e propriedade intelectual até 16/11.', 'Verificação: 10/11/2026. Datas e dotação REA confirmadas.', 'aberta', 1]);
    $st->execute(['HORIZON-MISS-2026-07-CROSS-04', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-MISS-2026-07-CROSS-04', 'C', 'C — Seleção principal — Estado por confirmar', 'Experimentação territorial para implementar as Missões da UE.', 'Sensores e pilotos apenas quando respondam a necessidades territoriais e de governação.', 'IA segundo base anterior; TRL e montantes fixos por confirmar.', '3,62 M€ indicados na base anterior, não revalidados nesta revisão.', 'Não confirmado.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Prazo anterior 13/01/2027; divergência ANI 12/01 às 23h continua pendente de confirmação no portal.', 'Consórcio de Missões com atores territoriais e procura tecnológica demonstrada.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 8/25 não validado. Probabilidade não estimável.', 'Validar tópico, hora oficial e caso de uso até 13/11, antes de assumir compromisso.', 'Verificação: 10/11/2026. PENDENTE: condições e calendário do tópico não revalidados integralmente nesta execução.', 'aberta', 1]);
    $st->execute(['HORIZON-BRIDGING-2027-06-02', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-BRIDGING-2027-06-02', 'C', 'C — Seleção principal — Aberto', 'Valorização de resultados do Horizonte Europa através de ecossistemas de inovação.', 'Possível papel em transferência de tecnologia e ecossistemas de robótica.', 'RIA segundo base anterior; TRL e montantes fixos por confirmar.', '20 M€ no concurso; apoio por projeto e taxa INESC TEC por validar.', 'Não confirmado.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '20/10/2026–16/02/2027. Hora no portal por confirmar. Resultados junho; assinatura setembro 2027, indicativos.', 'Necessário papel institucional credível e ligação aos resultados a valorizar.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 9/25 não validado. Probabilidade não estimável.', 'Confirmar papel substantivo na valorização e ecossistema até 23/11.', 'Verificação: 10/11/2026. Datas e dotação reconfirmadas pela REA.', 'aberta', 1]);
    $st->execute(['Water4All JTC 2026', 'https://www.water4all-partnership.eu/joint-activities/water4all-2026-joint-transnational-call', 'B', 'B — Radar futuro — 1.ª fase encerrada', 'Gestão sustentável da água: monitorização, infraestruturas e governação.', 'Sensores distribuídos, IoT e monitorização de solo/água; parceiro de consórcio.', 'Concurso transnacional Water4All; TRL e modalidade financeira por confirmar.', 'Cerca de 34 M€ internacionais. Financiamento português e taxa INESC TEC não confirmados.', 'Não indicado.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Pré-propostas encerraram em 10/11 às 15h CET = 14h Lisboa. Propostas completas: 06/04/2027 às 15h CEST = 14h Lisboa, apenas selecionados.', '≥3 parceiros financiados de 3 países; ≥2 entidades independentes UE/associados; máximo 7 incluindo 1 autofinanciado. Participação/financiamento PT por confirmar.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 10/25 desatualizado após fecho. Não é nova candidatura acessível; probabilidade não estimável.', 'Confirmar se foi submetida pré-proposta. Se não, acompanhar futuras edições.', 'Verificação: 10/11/2026. Página consultada e calendário confirmado às 16h12 Lisboa.', 'radar', 1]);
    $st->execute(['AGROECOLOGY Partnership — 4th call', 'https://www.agroecologypartnership.eu/en/news/pre-announcement-of-the-agroecology-partnership-4th-co-funded-call', 'B', 'B — Radar futuro — Pré-anúncio', 'Transição agroecológica, resiliência e living labs.', 'IoT e robótica para monitorizar práticas agroecológicas; parceiros agrícolas e multiator.', 'Investigação transnacional cofinanciada. TRL/modalidade dependem do aviso final.', '25 M€ indicativos; parcela e taxa portuguesas por confirmar.', 'Não publicado.', 'Por publicar no aviso e regras nacionais.', 'Previsão: lançamento dezembro 2026; pré-propostas fevereiro 2027; propostas completas julho; seleção outubro. Sem dias definitivos.', '≥3 parceiros financiados de ≥3 países participantes. Regras dos financiadores e participação portuguesa por confirmar.', 'Base anterior: 89 pré-propostas, 79 elegíveis, 39 convidadas à fase 2; dados não revalidados e não representam projetos financiados.', 'R anterior 12/25 não validado. Probabilidade não estimável.', 'Planear pilotos e verificar financiador português até 04/12; acompanhar publicação.', 'Verificação: 10/11/2026. Pré-anúncio de 27/10/2026 consultado.', 'radar', 1]);
    $st->execute(['HORIZON-CL6-2027-03-GOVERNANCE-04', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-CL6-2027-03-GOVERNANCE-04', 'B', 'B — Radar futuro — Abertura futura', 'IA para aconselhamento agrícola e florestal.', 'IoT/IA e apoio operacional; Manitoba é hipótese de parceria.', 'IA, montantes fixos; TRL final 8.', '11,8 M€; 5–6 M€/projeto; taxa efetiva INESC TEC por validar.', '2 indicativos.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '04/02/2027–11/05/2027, 17h Bruxelas = 16h Lisboa. Resultados/contrato por confirmar.', 'Abordagem multiator. Confirmar restrições de controlo e dados Copernicus/Galileo quando aplicáveis.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 12/25 não validado. Probabilidade não estimável.', 'Definir parceiros de aconselhamento e utilizadores até 15/01/2027.', 'Verificação: 10/11/2026. WP9 pp.41 e 317–320. Secções decisivas e calendário consultados.', 'radar', 1]);
    $st->execute(['HORIZON-CL6-2027-01-BIODIV-01', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-CL6-2027-01-BIODIV-01', 'B', 'B — Radar futuro — Abertura futura', 'Integração de deteção remota e observações in situ da biodiversidade.', 'UAV, sensores, interoperabilidade e IA com especialistas em ecologia; Manitoba hipotética.', 'RIA, montantes fixos. TRL não fixado nas secções revistas.', '10 M€; cerca de 5 M€/projeto; taxa efetiva por confirmar.', '2 indicativos.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '20/04/2027–22/09/2027, 17h Bruxelas = 16h Lisboa. Avaliação/contrato por confirmar.', 'Consórcio de biodiversidade e dados. Uso de Copernicus/Galileo quando aplicável.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 12/25 não validado. Probabilidade não estimável.', 'Mapear parceiros de biodiversidade e dados até 15/01/2027.', 'Verificação: 10/11/2026. WP9 pp.34–35 e 73–75. Secções decisivas e calendário consultados.', 'radar', 1]);
    $st->execute(['HORIZON-CL6-2027-02-FARM2FORK-01', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-CL6-2027-02-FARM2FORK-01', 'B', 'B — Radar futuro — Abertura futura', 'Inovação digital para agricultura com escassez de água e nutrientes.', 'Sensores de solo, IoT, IA e decisão de rega; pilotos agrícolas e Manitoba como hipótese.', 'RIA, montantes fixos; TRL final 4–5.', '12 M€; cerca de 6 M€/projeto; taxa efetiva por confirmar.', '2 indicativos.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '20/04/2027–23/09/2027, 17h Bruxelas = 16h Lisboa. Avaliação/contrato por confirmar.', 'Abordagem multiator com agricultores e conselheiros. Participação canadiana conforme regras do Pilar II e tópico.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'R anterior 13/25 não validado. Probabilidade não estimável.', 'Desenvolver conceito de rega digital e pilotos até 15/01/2027.', 'Verificação: 10/11/2026. WP9 pp.38 e 138–140. Secções decisivas e calendário consultados.', 'radar', 1]);
    $st->execute(['NOSTRADAMUS OC2', 'https://nostradamus-project.eu/nostradamus-open-calls/', 'C', 'C — Radar futuro — Previsto', 'Aplicações digitais de apoio à criação e avaliação de políticas agrícolas.', 'Observação da Terra, indicadores IoT e dados para decisores.', 'FSTP previsto. Não transferir automaticamente as regras da OC1.', '400 mil € para OC1+OC2 indicados na base anterior; montante específico OC2 por confirmar.', 'Não publicado.', 'Por publicar.', 'Lançamento previsto janeiro 2027. Prazo, avaliação e contratação por publicar.', 'Elegibilidade detalhada depende do futuro aviso OC2.', 'Sem edições OC2 concluídas.', 'R anterior 11/25 não validado. Probabilidade não estimável.', 'Acompanhar lançamento e regras próprias; revisão interna 15/01/2027.', 'Verificação: 10/11/2026.', 'radar', 1]);
    $st->execute(['IC&DT 2026 — Todos os domínios científicos', 'https://www.fct.pt/wp-content/uploads/2026/03/CalendarioConcursosFCT_2026.pdf', 'A', 'A — Radar futuro — Previsto', 'Investigação científica e desenvolvimento tecnológico.', 'Robótica, perceção, sensores e IA agrícola/florestal; possível coordenação ou participação.', 'Tipologia FCT prevista; TRL e modalidade de custos a aguardar aviso.', 'A aguardar aviso oficial: dotação, limite por projeto, taxa e cofinanciamento não confirmados.', 'A aguardar aviso oficial.', 'A aguardar aviso oficial: duração e prorrogação.', 'Previsão: aviso dezembro 2026; candidaturas janeiro–fevereiro 2027; resultados outubro 2027. Datas não definitivas.', 'Elegibilidade do INESC TEC, IR, parceiros e consórcio dependem do aviso desta edição.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável: dados históricos e prontidão efetiva insuficientes. Consórcios, pilotos e capacidade da equipa por confirmar.', 'Preparar conceito e equipa até 30/11; verificar aviso em dezembro.', 'Verificação: 10/11/2026. Calendário FCT 2026, p.2.', 'radar', 1]);
    $st->execute(['PEX 2026 — Todos os domínios científicos', 'https://www.fct.pt/wp-content/uploads/2026/03/CalendarioConcursosFCT_2026.pdf', 'A', 'A — Radar futuro — Previsto', 'Investigação exploratória e ideias inovadoras.', 'Prova de conceito em robótica, instrumentação e inteligência artificial; possível liderança.', 'Tipologia FCT prevista; TRL e modalidade de custos a aguardar aviso.', 'A aguardar aviso oficial: dotação, limite por projeto, taxa e cofinanciamento não confirmados.', 'A aguardar aviso oficial.', 'A aguardar aviso oficial: duração e prorrogação.', 'Previsão: aviso dezembro 2026; candidaturas janeiro–fevereiro 2027; resultados outubro 2027.', 'Elegibilidade do INESC TEC, IR, parceiros e consórcio dependem do aviso desta edição.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável: dados históricos e prontidão efetiva insuficientes.', 'Selecionar ideia exploratória e IR até 30/11; evitar duplicação do mesmo projeto nas duas tipologias.', 'Verificação: 10/11/2026. Calendário FCT 2026, p.2.', 'radar', 1]);
    $st->execute(['DEFESA + CIÊNCIA — edição 2026 prevista', 'https://www.fct.pt/wp-content/uploads/2026/03/CalendarioConcursosFCT_2026.pdf', 'B', 'B — Radar futuro — Previsto', 'Investigação exploratória orientada para defesa, dependente dos temas futuros.', 'Potencial em autonomia, navegação e perceção de dupla utilização; enquadramento temático por demonstrar.', 'Tipologia FCT prevista; TRL e modalidade de custos a aguardar aviso.', 'A aguardar aviso oficial: dotação, limite por projeto, taxa e cofinanciamento não confirmados.', 'A aguardar aviso oficial.', 'A aguardar aviso oficial: duração e prorrogação.', 'Sem prazo definitivo. Previsão dezembro 2026–janeiro 2027. Divergência a esclarecer.', 'Elegibilidade do INESC TEC, IR, parceiros e consórcio dependem do aviso desta edição.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Acompanhar FCT/IDN e esclarecer calendário e edição até 20/11 antes de planear submissão.', 'Verificação: 10/11/2026. Calendário FCT 2026, p.2.', 'radar', 1]);
    $st->execute(['CMU Portugal — Projetos Exploratórios, próxima edição', 'https://www.fct.pt/wp-content/uploads/2026/03/CalendarioConcursosFCT_2026.pdf', 'B', 'B — Radar futuro — Previsto', 'Colaboração exploratória internacional nas áreas a definir.', 'Potencial em IA, robótica e sistemas inteligentes; relação com investigadores CMU por construir/confirmar.', 'Tipologia FCT prevista; TRL e modalidade de custos a aguardar aviso.', 'A aguardar aviso oficial: dotação, limite por projeto, taxa e cofinanciamento não confirmados.', 'A aguardar aviso oficial.', 'A aguardar aviso oficial: duração e prorrogação.', 'Previsão: aviso dezembro 2026; candidaturas dezembro 2026–janeiro 2027; resultados maio 2027.', 'Elegibilidade do INESC TEC, IR, parceiros e consórcio dependem do aviso desta edição.', 'Uma edição localizada: 2025, 36 candidaturas e 7 aprovadas (19,4%). Não constitui série de 3 edições nem probabilidade TRIBE.', 'Não estimável.', 'Mapear competências e possíveis interlocutores científicos até 30/11; confirmar consórcio no novo aviso.', 'Verificação: 10/11/2026. Calendário FCT 2026, p.2.', 'radar', 1]);
    $st->execute(['UT Austin Portugal — Projetos Exploratórios, próxima edição', 'https://www.fct.pt/wp-content/uploads/2026/03/CalendarioConcursosFCT_2026.pdf', 'B', 'B — Radar futuro — Previsto', 'Colaboração exploratória nas áreas futuras do programa.', 'Potencial em computação, sensores e tecnologias digitais, sujeito aos termos de referência; parceria por confirmar.', 'Tipologia FCT prevista; TRL e modalidade de custos a aguardar aviso.', 'A aguardar aviso oficial: dotação, limite por projeto, taxa e cofinanciamento não confirmados.', 'A aguardar aviso oficial.', 'A aguardar aviso oficial: duração e prorrogação.', 'Previsão: aviso dezembro 2026; candidaturas dezembro 2026–janeiro 2027; resultados maio 2027.', 'Elegibilidade do INESC TEC, IR, parceiros e consórcio dependem do aviso desta edição.', 'Uma edição localizada: 2025, 40 candidaturas e 8 aprovadas (20%). Série de 3 edições incompleta.', 'Não estimável.', 'Verificar temas elegíveis e necessidade de colaboração UT Austin após publicação.', 'Verificação: 10/11/2026. Calendário FCT 2026, p.2.', 'radar', 1]);
    $st->execute(['FCT/CPCA/2026/01 — Computação Avançada, 7.ª edição', 'https://www.fct.pt/concursos/concurso-de-projetos-de-computacao-avancada-7-ed-a0-a1-lote-a-', 'C', 'C — Seleção principal — Aberto', 'Acesso a computação para IA e simulação.', 'Treino de modelos, perceção robótica e simulação. Apoio complementar a projetos I&D.', 'Recursos computacionais A0–A3; lote A em análise: A0/A1.', 'Sem subvenção monetária. A0: até 50 mil CPU core.h e 250 GPU.h; A1: 100 mil CPU core.h e 1000 GPU.h.', 'Sem número fixo; depende dos recursos.', 'A0 até 6 meses; A1 até 12. Extensão justificada até +3 meses.', 'Lote A: 22/09–17/11/2026, 17h Lisboa. Próximos fechos previstos: 19/01, 16/03, 15/06 e 14/09/2027.', 'Instituições I&D elegíveis; A0/A1 validados tecnicamente.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Definir recursos e submeter A0/A1 até 16/11, prazo interno sugerido.', 'Verificação: 10/11/2026. Página, aviso 16 pp. e Regulamento 1159/2026 consultados.', 'aberta', 1]);
    $st->execute(['MPr-2026-4 — Internacionalização de I&D', 'https://compete2030.gov.pt/avisos/siid-internacionalizacao-de-id-operacoes-de-id-industrial-a-escala-europeia_mpr-2026-4/', 'B', 'B — Seleção principal — Aberto', 'Cofinanciamento português de projetos EUREKA/Eurostars aprovados.', 'Parceiro de I&D em projeto internacional elegível. Não substitui candidatura internacional.', 'Investigação industrial/desenvolvimento experimental.', '10 M€ no aviso. ENESII até 85%, Lisboa até 40%, sob condições; taxa concreta por validar.', 'Não fixado.', 'Até 36 meses; exceções justificadas e aceites.', 'Prazo fase 3: 29/12/2026, 17h Lisboa.', 'Selo/aprovação internacional válido, promotores nacionais do projeto e ausência de cofinanciamento europeu para a operação.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Verificar se existe projeto internacional já aprovado até 16/11.', 'Verificação: 10/11/2026. Republicação 22/07, pp.4–7 e 13–15 consultadas.', 'aberta', 1]);
    $st->execute(['MPr-2026-9 — STEP Digital e Biotecnologia', 'https://compete2030.gov.pt/avisos/step-idi-empresarial-digital-e-biotecnologia_mpr-2026-9/', 'B', 'B — Seleção principal — Aberto', 'I&D e inovação produtiva de tecnologias críticas STEP.', 'Robótica/IA/sensores somente com enquadramento STEP demonstrável e parceiro industrial.', 'I&D&I em copromoção; TRL sobretudo ≥6, podendo iniciar em 4 e chegar à produção.', '76 M€; ENESII até 85% na componente I&D sob condições. Taxa concreta e investimento por validar.', 'Não fixado.', '36 meses, exceto casos justificados.', '30/09/2026–04/01/2027, 17h Lisboa. Decisão 90 dias úteis após fecho, com ressalvas.', 'Empresas e ENESII em copromoção; território e tecnologia crítica elegíveis; validar condições de cada componente.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Verificar tecnologia crítica e capacidade industrial até 20/11.', 'Verificação: 10/11/2026. Aviso, pp.3–6,11–12,28 consultadas.', 'aberta', 1]);
    $st->execute(['Eurostars Call 12', 'https://www.eurekanetwork.org/programmes-and-calls/eurostars/eurostars-call-12-for-projects-deadline-march-2027/', 'B', 'B — Radar futuro — Abertura futura', 'I&D internacional orientada para novos produtos, processos e serviços.', 'Robótica agrícola/florestal com PME inovadora e parceiros internacionais.', 'Eurostars; TRL e regras nacionais concretas por validar.', 'Financiamento nacional. Taxa e teto portugueses da Call 12 não validados nesta execução; não aplicar automaticamente 85%.', 'Não confirmado.', 'Até 36 meses segundo regras gerais; confirmar edição e extensões.', 'Abertura anunciada 17/12/2026; prazo 04/03/2027, 14h CET = 13h Lisboa.', 'Liderança PME inovadora e cooperação internacional. Elegibilidade portuguesa do INESC TEC condicionada às regras nacionais.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Confirmar PME líder e regras portuguesas antes da abertura. Não confundir com MPr-2026-4.', 'Verificação: 10/11/2026. Índice oficial Eureka confirma Call 12 e 04/03/2027.', 'radar', 1]);
    $st->execute(['HORIZON-CL4-2027-04-DIGITAL-EMERGING-05', 'https://ec.europa.eu/info/funding-tenders/opportunities/portal/screen/opportunities/topic-details/HORIZON-CL4-2027-04-DIGITAL-EMERGING-05', 'B', 'B — Radar futuro — Abertura futura', 'Integração e adoção de robótica com IA na indústria.', 'Competências CRIIS em integração robótica e perceção; aplicação agrícola/florestal requer encaixe demonstrável.', 'IA; TRL 4→7. Modalidade financeira a confirmar.', '18 M€ no tópico; cerca de 18 M€/projeto. Taxa INESC TEC por validar.', '1 indicativo.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', '17/11/2026–18/03/2027, 17h Bruxelas = 16h Lisboa. Avaliação/contrato por confirmar.', 'Restrições de país e controlo; Canadá admitido sob condições. Necessários integradores e utilizadores industriais.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Avaliar consórcio industrial até 30/11.', 'Verificação: 10/11/2026. WP7, versão 29/09/2026, pp.31 e 151–154 consultadas.', 'radar', 1]);
    $st->execute(['FCT RESTART — 4.ª edição', 'https://www.fct.pt/concursos/programa-restart-4-edicao', 'C', 'C — Seleção principal — Aberto, condicional', 'Regresso competitivo à investigação após licença parental.', 'Possível projeto individual em robótica ou sensores, apenas com IR elegível; não é concurso geral para a equipa.', 'Projeto individual de I&D; TRL por confirmar.', 'Dotação 1,6 M€; máximo 50 mil €/projeto. Taxa e regras de custos a validar.', 'Não indicado.', 'Máximo 18 meses; extensão por confirmar.', '01/10/2026–13/11/2026, 17h Lisboa. Resultados previstos abril 2027.', 'Doutor integrado em unidade I&D ou vinculado a laboratório de Estado; licença parental elegível.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não estimável.', 'Se existir IR que cumpra as condições pessoais: validar e preparar submissão. Não presumir licença parental de qualquer investigador.', 'Verificação: 10/11/2026. Página e alteração de 08/10 consultadas.', 'aberta', 1]);
    $st->execute(['Biodiversa+ BiodivFuture 2026–2027', 'https://www.biodiversa.eu/2026/06/09/2026-2027-joint-call/', 'C', 'C — Radar futuro — 1.ª fase encerrada', 'Novos ecossistemas, biodiversidade e trajetórias socioecológicas.', 'Sensores e observação robótica como contribuição para consórcio liderado por ecologia; encaixe por demonstrar.', 'Concurso transnacional em duas fases; TRL/modalidade a validar.', 'Dotação e financiamento português por confirmar; sem valor atribuído ao INESC TEC.', 'Não confirmado.', 'Duração e limite de prorrogação não confirmados. Não assumir aumento de apoio em caso de extensão.', 'Pré-propostas encerraram 10/11/2026 às 12h CET = 11h Lisboa. Propostas completas: 16/04/2027, só consórcios admitidos.', 'Pré-proposta obrigatória. Participação e regras de financiamento portuguesas não verificadas.', 'Sem contagens oficiais completas de candidaturas e financiamentos nas últimas 3 edições comparáveis. Taxa agregada indisponível.', 'Não é nova candidatura acessível após fecho da fase 1; probabilidade não estimável.', 'Confirmar se existe pré-proposta submetida. Caso contrário acompanhar próxima edição.', 'Verificação: 10/11/2026. Página oficial consultada.', 'radar', 1]);
    $st->execute(['FORESTPathways — Joint Call 2026', 'https://forest-partnership.eu/jointcall2026/', 'C', 'C — Radar futuro — Aberto, sem financiamento PT', 'Florestas resilientes, gestão multifuncional e cadeias de valor florestais.', 'Potencial para sensores, deteção remota e robótica de monitorização florestal. Participação do INESC TEC apenas como parceiro associado com recursos externos, por confirmar.', 'Concurso transnacional em duas fases; TRL depende do financiador.', 'Até 40 M€ na call. Portugal não consta do Anexo II nem dos financiadores do aviso. Sem financiamento direto identificado para INESC TEC.', 'Número não fixado.', '36 meses. Limite de prorrogação não confirmado.', 'Pré-proposta até 02/12/2026 às 13h CET = 12h Lisboa. Proposta completa só por convite: 02/06/2027.', '≥3 parceiros financiados/3 países participantes, incluindo ≥2 países elegíveis para apoio UE. Associado não conta para o mínimo.', 'Primeira Joint Call FOREST; não há três edições próprias comparáveis.', 'Não estimável. Encaixe científico potencial, mas não é apoio financeiro acessível ao INESC TEC nas condições verificadas.', 'Até 17/11: avaliar interesse científico e recursos externos. Só avançar com confirmação do papel associado e consórcio financiado.', 'Verificação: 10/11/2026. Aviso: pp.3 calendário, 6 financiadores, 11–14 duração/associados/elegibilidade.', 'radar', 1]);
    $st->execute(['ERC-2027-CoG — Consolidator Grant', 'https://erc.europa.eu/apply-grant/consolidator-grant', 'B', 'B — Seleção principal — Aberto, PI por validar', 'Investigação de fronteira em qualquer área, orientada pela excelência científica.', 'Potencial para investigação original em autonomia, perceção e robótica agrícola/florestal. Exige PI independente e proposta de fronteira; não basta demonstração tecnológica.', 'ERC Frontier Research Action; custos reais. Sem TRL obrigatório.', 'Até 2 M€/projeto; adicional até 1 M€ justificado, ou até 2 M€ adicional para relocalização elegível. Dotação CoG 618,5 M€.', '296 bolsas estimadas no WP2027.', 'Até 60 meses.', '24/09/2026–12/01/2027; hora no tópico por confirmar. Calendário indicativo ERC: convites junho, entrevistas set.–out., resultados dezembro 2027.', 'PI com defesa do primeiro doutoramento entre 01/01/2012 e 31/12/2021, salvo extensões documentadas. Instituição de acolhimento UE/associado e compromisso de independência.', 'Série de 3 edições comparáveis não verificada.', 'Não estimável: PI elegível, currículo, proposta e prontidão ainda não avaliados.', 'Validar PI, janela de elegibilidade, ressubmissões e apoio institucional até 20/11.', 'Verificação: 10/11/2026. Fonte primária: página ERC e WP2027 pp.10–15,18 e 53 consultadas.', 'aberta', 1]);
    $st->execute(['GRAIL Genesis — Open Call 1', 'https://grail-robotics.ai/open-call/genesis/', 'B', 'B — Seleção principal — Aberto', 'Dados multimodais, ferramentas de captura e avaliação de IA para robótica.', 'Robótica móvel, perceção, sincronização de sensores e dados para navegação. Papel possível do CRIIS, capacidade e dados por confirmar.', 'FSTP em montantes fixos; TRL 2→4 esperado, não obrigatório.', '1 M€ na call; até 100 mil €/projeto, taxa 100%.', 'Pelo menos 10 projetos previstos.', 'Até 8 meses.', '09/11/2026 às 09h CET–10/02/2027 às 17h CET (=16h Lisboa).', 'Entidade única, sem consórcio; RTOs elegíveis e Portugal admitido como Estado-Membro.', 'Primeira Genesis; sem três edições próprias comparáveis.', 'Não estimável. Encaixe técnico potencial CRIIS; disponibilidade de dados, ambiente operacional e prontidão não confirmadas.', 'Até 20/11: escolher tópico e verificar dados/robô disponíveis, elegibilidade e retenção financeira.', 'Verificação: 11/11/2026. Página oficial, T&C e Anexo 1 consultados.', 'aberta', 1]);
    $st->execute(['Factory X 2026–2027 — EIT AI & Robotics Community', 'https://ai.eitcommunity.eu/factory-x', 'B', 'B — Seleção principal — Anunciado, acesso por confirmar', 'Valorização e adoção industrial de IA e robótica.', 'Potencial parceiro de investigação/integração em autonomia e IA. Requer venture, empresa e AI Factory; não há consórcio TRIBE confirmado.', 'Programa EIT de inovação; TRL 6–8. Modalidade contratual por confirmar no aviso integral.', '1,8 M€ na call; 500–900 mil €/projeto. Recursos próprios ≥30% do custo total.', 'Até 2 projetos.', '01/03–30/11/2027 (9 meses). Extensões por confirmar.', 'Prazo anunciado: 15/12/2026 às 17h CET (=16h Lisboa). Entrevistas janeiro 2027.', '2–4 organizações independentes de países elegíveis distintos. Venture ≤5 anos, ≥2 FTE e maioria da PI; corporate e AI Factory obrigatórios.', 'Sem série de três edições comparáveis verificada.', 'Não estimável.', 'Até 20/11: confirmar aviso integral/formulário, parceiros obrigatórios e condições de cofinanciamento.', 'Verificação: 11/11/2026. Página e FAQ oficiais consultadas.', 'aberta', 1]);
    } catch (Exception $e) {}
}

// ─── Processar actions POST ────────────────────────────────────────────────────
$fcAction = $_POST['fc_action'] ?? '';
$fcMsg = '';
$currentUserId = $_SESSION['user_id'] ?? 0;
$uJoin = "(SELECT DISTINCT user_id, username FROM user_tokens)";

if ($fcAction === 'add_note' && $currentUserId) {
    $callId = (int)($_POST['call_id'] ?? 0);
    $noteText = trim($_POST['note_text'] ?? '');
    if ($callId && $noteText) {
        try {
            $pdo->prepare("INSERT INTO funding_call_notes (call_id, user_id, note_text) VALUES (?,?,?)")
                ->execute([$callId, $currentUserId, $noteText]);
            $fcMsg = 'nota_ok';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'edit_call' && $currentUserId) {
    $callId = (int)($_POST['call_id'] ?? 0);
    if ($callId) {
        try {
            $pdo->prepare("UPDATE funding_calls SET titulo=?, url=?, prioridade=?, prioridade_label=?, area=?, encaixe=?, tipo_trl=?, financiamento=?, projetos_apoiados=?, duracao=?, datas=?, elegibilidade=?, sucesso_anteriores=?, probabilidade=?, proximo_passo=?, fontes=?, estado=?, notas_internas=? WHERE id=?")
                ->execute([
                    trim($_POST['titulo'] ?? ''), trim($_POST['url'] ?? ''),
                    trim($_POST['prioridade'] ?? 'C'), trim($_POST['prioridade_label'] ?? ''),
                    trim($_POST['area'] ?? ''), trim($_POST['encaixe'] ?? ''),
                    trim($_POST['tipo_trl'] ?? ''), trim($_POST['financiamento'] ?? ''),
                    trim($_POST['projetos_apoiados'] ?? ''), trim($_POST['duracao'] ?? ''),
                    trim($_POST['datas'] ?? ''), trim($_POST['elegibilidade'] ?? ''),
                    trim($_POST['sucesso_anteriores'] ?? ''), trim($_POST['probabilidade'] ?? ''),
                    trim($_POST['proximo_passo'] ?? ''), trim($_POST['fontes'] ?? ''),
                    trim($_POST['estado'] ?? 'aberta'), trim($_POST['notas_internas'] ?? ''),
                    $callId
                ]);
            $fcMsg = 'edit_ok';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'add_call' && $currentUserId) {
    $titulo = trim($_POST['titulo'] ?? '');
    if ($titulo) {
        try {
            $pdo->prepare("INSERT INTO funding_calls (titulo, url, prioridade, prioridade_label, area, encaixe, tipo_trl, financiamento, projetos_apoiados, duracao, datas, elegibilidade, sucesso_anteriores, probabilidade, proximo_passo, fontes, estado, notas_internas, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([
                    $titulo, trim($_POST['url'] ?? ''),
                    trim($_POST['prioridade'] ?? 'C'), trim($_POST['prioridade_label'] ?? ''),
                    trim($_POST['area'] ?? ''), trim($_POST['encaixe'] ?? ''),
                    trim($_POST['tipo_trl'] ?? ''), trim($_POST['financiamento'] ?? ''),
                    trim($_POST['projetos_apoiados'] ?? ''), trim($_POST['duracao'] ?? ''),
                    trim($_POST['datas'] ?? ''), trim($_POST['elegibilidade'] ?? ''),
                    trim($_POST['sucesso_anteriores'] ?? ''), trim($_POST['probabilidade'] ?? ''),
                    trim($_POST['proximo_passo'] ?? ''), trim($_POST['fontes'] ?? ''),
                    trim($_POST['estado'] ?? 'aberta'), trim($_POST['notas_internas'] ?? ''),
                    $currentUserId
                ]);
            $fcMsg = 'add_ok';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'create_scout_key' && $currentUserId) {
    $keyName = trim($_POST['key_name'] ?? '');
    if ($keyName) {
        $newKey = 'sk-' . bin2hex(random_bytes(20));
        try {
            $pdo->prepare("INSERT INTO funding_scout_keys (name, api_key, created_by) VALUES (?,?,?)")
                ->execute([$keyName, $newKey, $currentUserId]);
            $fcMsg = 'key_created:' . $newKey;
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'revoke_scout_key' && $currentUserId) {
    $keyId = (int)($_POST['key_id'] ?? 0);
    if ($keyId) {
        try {
            $pdo->prepare("DELETE FROM funding_scout_keys WHERE id=?")->execute([$keyId]);
            $fcMsg = 'key_revoked';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'convert_to_lead' && $currentUserId) {
    $callId = (int)($_POST['call_id'] ?? 0);
    if ($callId) {
        try {
            $fc = $pdo->prepare("SELECT * FROM funding_calls WHERE id=?");
            $fc->execute([$callId]);
            $fc = $fc->fetch(PDO::FETCH_ASSOC);
            if ($fc && !$fc['lead_id']) {
                // Extrair deadline para data_fim
                $fcDl = fcExtractDeadline($fc['datas'] ?? '');
                $dataFim = $fcDl ? $fcDl->format('Y-m-d') : null;
                // relevancia: A=9, B=6, C=3
                $relevancia = ['A'=>9,'B'=>6,'C'=>3][$fc['prioridade']] ?? 5;
                $descricao = implode("\n\n", array_filter([
                    $fc['area'] ? "**Área:** " . $fc['area'] : '',
                    $fc['encaixe'] ? "**Encaixe TRIBE:** " . $fc['encaixe'] : '',
                    $fc['financiamento'] ? "**Financiamento:** " . $fc['financiamento'] : '',
                    $fc['datas'] ? "**Datas:** " . $fc['datas'] : '',
                    $fc['elegibilidade'] ? "**Elegibilidade:** " . $fc['elegibilidade'] : '',
                    $fc['proximo_passo'] ? "**Próximo passo:** " . $fc['proximo_passo'] : '',
                    $fc['url'] ? "**Link oficial:** " . $fc['url'] : '',
                ]));
                $stLead = $pdo->prepare("INSERT INTO leads (titulo, descricao, relevancia, responsavel_id, data_fim, estado) VALUES (?,?,?,?,?,'aberta')");
                $stLead->execute([$fc['titulo'], $descricao, $relevancia, $currentUserId, $dataFim]);
                $leadId = (int)$pdo->lastInsertId();
                // Ligar a call à lead
                $pdo->prepare("UPDATE funding_calls SET lead_id=? WHERE id=?")->execute([$leadId, $callId]);
                // Adicionar link da call à lead
                if ($fc['url']) {
                    $pdo->prepare("INSERT INTO lead_links (lead_id, titulo, url) VALUES (?,?,?)")
                        ->execute([$leadId, $fc['titulo'], $fc['url']]);
                }
                // Nota de origem
                $pdo->prepare("INSERT INTO lead_notes (lead_id, user_id, note_text) VALUES (?,?,?)")
                    ->execute([$leadId, $currentUserId, "Lead criada a partir da oportunidade de financiamento: " . $fc['titulo'] . " (Prioridade " . $fc['prioridade'] . ")"]);
                $fcMsg = 'lead_created:' . $leadId;
            } elseif ($fc && $fc['lead_id']) {
                $fcMsg = 'lead_exists:' . $fc['lead_id'];
            }
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'archive_call') {
    $callId = (int)($_POST['call_id'] ?? 0);
    if ($callId) {
        try {
            $pdo->prepare("UPDATE funding_calls SET estado='arquivada' WHERE id=?")->execute([$callId]);
            $fcMsg = 'archived';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
} elseif ($fcAction === 'delete_call') {
    $callId = (int)($_POST['call_id'] ?? 0);
    if ($callId) {
        try {
            $pdo->prepare("DELETE FROM funding_calls WHERE id=?")->execute([$callId]);
            $fcMsg = 'deleted';
        } catch (Exception $e) { $fcMsg = 'erro'; }
    }
}

// ─── Carregar dados ────────────────────────────────────────────────────────────
$allCalls = $pdo->query("SELECT * FROM funding_calls ORDER BY FIELD(prioridade,'A','B','C'), titulo")->fetchAll(PDO::FETCH_ASSOC);

// Extrair deadline do campo 'datas' e ordenar: prioridade A→B→C, depois deadline asc (sem data fica no fim)
function fcExtractDeadline(string $datas): ?DateTime {
    // procura padrões dd/mm/yyyy ou yyyy-mm-dd
    if (preg_match('/(\d{2})\/(\d{2})\/(\d{4})/', $datas, $m)) {
        $d = DateTime::createFromFormat('d/m/Y', $m[0]);
        return $d ?: null;
    }
    if (preg_match('/(\d{4})-(\d{2})-(\d{2})/', $datas, $m)) {
        $d = DateTime::createFromFormat('Y-m-d', $m[0]);
        return $d ?: null;
    }
    return null;
}

// Ordenação por omissão: deadline mais próxima primeiro; sem deadline fica no fim;
// empate de deadline desempata por prioridade A→B→C, depois título.
$prioOrder = ['A' => 0, 'B' => 1, 'C' => 2];
usort($allCalls, function($a, $b) use ($prioOrder) {
    $da = fcExtractDeadline($a['datas'] ?? '');
    $db = fcExtractDeadline($b['datas'] ?? '');
    if (!$da && !$db) {
        // Ambas sem deadline: ordena por prioridade depois título
        $pa = $prioOrder[$a['prioridade']] ?? 2;
        $pb = $prioOrder[$b['prioridade']] ?? 2;
        return $pa !== $pb ? $pa - $pb : strcmp($a['titulo'], $b['titulo']);
    }
    if (!$da) return 1;   // sem deadline vai para o fim
    if (!$db) return -1;
    if ($da != $db) return $da <=> $db;  // deadline mais próxima primeiro
    // Mesma deadline: desempata por prioridade
    $pa = $prioOrder[$a['prioridade']] ?? 2;
    $pb = $prioOrder[$b['prioridade']] ?? 2;
    return $pa !== $pb ? $pa - $pb : strcmp($a['titulo'], $b['titulo']);
});

$scoutKeys = [];
try {
    $scoutKeys = $pdo->query("SELECT fsk.*, u.username FROM funding_scout_keys fsk LEFT JOIN $uJoin u ON u.user_id = fsk.created_by ORDER BY fsk.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

$callsById = [];
foreach ($allCalls as $c) { $callsById[$c['id']] = $c; }

// Carregar notas (agrupadas por call_id)
$allNotes = [];
try {
    $notesRaw = $pdo->query("SELECT fcn.*, u.username FROM funding_call_notes fcn LEFT JOIN $uJoin u ON u.user_id = fcn.user_id ORDER BY fcn.created_at ASC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($notesRaw as $n) { $allNotes[$n['call_id']][] = $n; }
} catch (Exception $e) {}

$pBadge = ['A' => '<span class="badge bg-danger">A</span>', 'B' => '<span class="badge bg-warning text-dark">B</span>', 'C' => '<span class="badge bg-secondary">C</span>'];
$estadoBadge = [
    'aberta'    => '<span class="badge bg-success">Aberta</span>',
    'radar'     => '<span class="badge bg-info text-dark">Radar</span>',
    'fechada'   => '<span class="badge bg-secondary">Fechada</span>',
    'arquivada' => '<span class="badge bg-dark">Arquivada</span>',
];
?>

<div>
<div class="d-flex align-items-center justify-content-between mb-3">
  <h5 class="mb-0 d-flex align-items-center gap-2">
    <i class="bi bi-telescope-fill text-primary"></i>
    <span>Oportunidades de Financiamento</span>
    <small class="text-muted fs-6">(<?= count($allCalls) ?> calls)</small>
  </h5>
  <div class="d-flex gap-2">
    <button class="btn btn-sm btn-outline-primary" onclick="fcShowAdd()"><i class="bi bi-plus-lg me-1"></i>Nova Oportunidade</button>
  </div>
</div>

<div id="fc-section">

<?php if (str_starts_with($fcMsg,'lead_created:')): $newLeadId = (int)substr($fcMsg,13); ?>
<div class="alert alert-success alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  <i class="bi bi-check-circle me-1"></i><strong>Lead criada!</strong>
  <a href="?tab=leads&subtab=leads&lead_id=<?= $newLeadId ?>" class="alert-link ms-2">Ver Lead →</a>
</div>
<?php elseif (str_starts_with($fcMsg,'lead_exists:')): $existsLeadId = (int)substr($fcMsg,12); ?>
<div class="alert alert-info alert-dismissible"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  <i class="bi bi-info-circle me-1"></i>Já existe uma lead para esta oportunidade.
  <a href="?tab=leads&subtab=leads&lead_id=<?= $existsLeadId ?>" class="alert-link ms-2">Ver Lead →</a>
</div>
<?php endif; ?>
<?php if ($fcMsg === 'nota_ok'): ?><div class="alert alert-success alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Nota adicionada.</div><?php endif; ?>
<?php if ($fcMsg === 'edit_ok'): ?><div class="alert alert-success alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Oportunidade atualizada.</div><?php endif; ?>
<?php if ($fcMsg === 'add_ok'): ?><div class="alert alert-success alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Oportunidade adicionada.</div><?php endif; ?>
<?php if ($fcMsg === 'archived'): ?><div class="alert alert-secondary alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Oportunidade arquivada. <a href="?tab=leads&subtab=exploracao&show_archived=1" class="alert-link">Ver arquivadas</a></div><?php endif; ?>
<?php if ($fcMsg === 'deleted'): ?><div class="alert alert-warning alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Oportunidade eliminada definitivamente.</div><?php endif; ?>
<?php if ($fcMsg === 'key_revoked'): ?><div class="alert alert-info alert-dismissible py-2"><button type="button" class="btn-close" data-bs-dismiss="alert"></button>Chave removida.</div><?php endif; ?>
<?php if (str_starts_with($fcMsg, 'key_created:')): $newCreatedKey = substr($fcMsg, 12);
// Encontrar o nome desta chave para mostrar nos prompts
$newKeyName = '';
foreach ($scoutKeys as $sk) { if ($sk['api_key'] === $newCreatedKey) { $newKeyName = $sk['name']; break; } }
?>
<div class="alert alert-success alert-dismissible">
  <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
  <strong>Chave criada!</strong> Guarda agora — só é mostrada uma vez:
  <code class="user-select-all ms-1"><?= htmlspecialchars($newCreatedKey) ?></code>
  <button class="btn btn-sm btn-outline-secondary ms-2" onclick="navigator.clipboard.writeText(<?= json_encode($newCreatedKey) ?>)">Copiar</button>
  <button class="btn btn-sm btn-outline-primary ms-1" onclick="fcShowScoutPrompts(<?= json_encode($newCreatedKey) ?>, <?= json_encode($newKeyName) ?>)">Ver Prompts</button>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    fcShowScoutPrompts(<?= json_encode($newCreatedKey) ?>, <?= json_encode($newKeyName) ?>);
});
</script>
<?php endif; ?>

<!-- ── Chaves IA Scout — sempre visível ───────────────────────────────────── -->
<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-transparent d-flex align-items-center justify-content-between py-2"
       style="cursor:pointer" onclick="this.nextElementSibling.classList.toggle('d-none')">
    <span class="fw-semibold"><i class="bi bi-robot me-2 text-primary"></i>Assistentes IA Scout
      <small class="text-muted fw-normal ms-2"><?= count($scoutKeys) ?> chave<?= count($scoutKeys) != 1 ? 's' : '' ?></small>
    </span>
    <i class="bi bi-chevron-down text-muted"></i>
  </div>
  <div class="card-body pt-2<?= $scoutKeys ? '' : '' ?>">
    <p class="text-muted small mb-3">
      Cada chave permite a um assistente de IA aceder às oportunidades via
      <code>GET <?= htmlspecialchars((isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST']) ?>/PK/api/opportunities_context.php</code>
      e adicionar notas via POST. Cria uma chave por assistente.
    </p>

    <!-- Criar nova chave -->
    <form method="post" class="d-flex gap-2 mb-3 align-items-end">
      <input type="hidden" name="fc_action" value="create_scout_key">
      <input type="hidden" name="subtab" value="exploracao">
      <div>
        <label class="form-label small mb-1">Nome do assistente</label>
        <input type="text" name="key_name" class="form-control form-control-sm" placeholder="ex: Claude Scout, GPT Opportunities…" required style="min-width:260px">
      </div>
      <button class="btn btn-sm btn-primary" type="submit"><i class="bi bi-plus-lg me-1"></i>Criar Chave</button>
    </form>

    <?php if ($scoutKeys): ?>
    <div class="d-flex flex-column gap-2">
    <?php foreach ($scoutKeys as $sk):
      $skPanelId = 'sk-panel-' . $sk['id'];
      $autoOpen = str_starts_with($fcMsg, 'key_created:') && substr($fcMsg, 12) === $sk['api_key'];
      $baseUrl = (isset($_SERVER['HTTPS'])&&$_SERVER['HTTPS']==='on'?'https':'http').'://'.$_SERVER['HTTP_HOST'];
      $apiEndpoint = $baseUrl . '/PK/api/opportunities_context.php';
      $key = $sk['api_key'];
      $name = $sk['name'];
      // Schema OpenAPI como string literal (sem interpolação dupla)
      $openApiSchema = 'openapi: 3.1.0
info:
  title: TRIBE Opportunities API
  version: 1.0.0
servers:
  - url: ' . $baseUrl . '/PK/api
paths:
  /opportunities_context.php:
    get:
      operationId: getTribeOpportunities
      summary: List all funding opportunities
      parameters:
        - name: estado
          in: query
          schema: {type: string, enum: [aberta, radar, fechada, arquivada]}
        - name: prioridade
          in: query
          schema: {type: string, enum: [A, B, C]}
      security:
        - ApiKeyAuth: []
    post:
      operationId: writeOpportunity
      summary: Add note, update fields, or create a new opportunity
      requestBody:
        content:
          application/json:
            schema:
              type: object
              required: [action]
              properties:
                action:
                  type: string
                  enum: [add_note, update_call, add_call]
                call_id:
                  type: integer
                  description: Required for add_note and update_call
                note_text:
                  type: string
                  description: Required for add_note
                titulo:
                  type: string
                  description: Required for add_call; optional for update_call
                url: {type: string}
                prioridade: {type: string, enum: [A, B, C]}
                estado: {type: string, enum: [aberta, fechada, radar, arquivada]}
                area: {type: string}
                encaixe: {type: string}
                financiamento: {type: string}
                datas: {type: string}
                elegibilidade: {type: string}
                probabilidade: {type: string}
                proximo_passo: {type: string}
                notas_internas: {type: string}
      security:
        - ApiKeyAuth: []
components:
  securitySchemes:
    ApiKeyAuth:
      type: apiKey
      in: header
      name: X-Api-Key';
      // Bloco de instruções partilhado (mesmo conteúdo, traduzido por assistente)
      $instrucoesPT = "És um assistente especializado no scouting de oportunidades de financiamento para a equipa TRIBE do INESC TEC.

## Contexto da equipa
Competências: robótica móvel, UAV (drones), visão computacional, IA/ML, IoT, monitorização agrícola e florestal, sensores ambientais, telemetria, edge computing.

## Acesso à API
Endpoint: {$apiEndpoint}
Autenticação: cabeçalho  X-Api-Key: {$key}

GET {$apiEndpoint}                        → lista todas as oportunidades (JSON)
GET {$apiEndpoint}?estado=aberta          → filtra por estado (aberta/radar/fechada/arquivada)
GET {$apiEndpoint}?prioridade=A           → filtra por prioridade (A/B/C)

Cada oportunidade tem os campos:
  id, titulo, url, prioridade (A/B/C), estado, area, encaixe, financiamento,
  duracao, datas (prazo), elegibilidade, probabilidade, proximo_passo, notes[]

## Ações de escrita (POST {$apiEndpoint})
Cabeçalhos obrigatórios: X-Api-Key: {$key}  |  Content-Type: application/json

Adicionar nota:
  {\"action\":\"add_note\", \"call_id\":ID, \"note_text\":\"texto\"}

Atualizar campos de uma oportunidade existente:
  {\"action\":\"update_call\", \"call_id\":ID, \"prioridade\":\"A\", \"estado\":\"aberta\",
   \"proximo_passo\":\"...\", \"probabilidade\":\"...\", \"datas\":\"prazo: dd/mm/aaaa\", ...}
  Campos editáveis: titulo, url, prioridade, area, encaixe, tipo_trl, financiamento,
  projetos_apoiados, duracao, datas, elegibilidade, sucesso_anteriores, probabilidade,
  proximo_passo, fontes, estado, notas_internas

Adicionar nova oportunidade:
  {\"action\":\"add_call\", \"titulo\":\"Nome da call\", \"url\":\"https://...\",
   \"prioridade\":\"B\", \"estado\":\"radar\", \"area\":\"...\", \"datas\":\"prazo: dd/mm/aaaa\"}
  Apenas titulo é obrigatório; todos os outros campos são opcionais.

## Comportamento obrigatório
1. ATUALIZAR dados existentes — quando encontrares informação mais recente sobre uma call
   já registada (novo prazo, dotação, link, elegibilidade), usa update_call imediatamente.
   Nunca deixes dados desatualizados.

2. ADICIONAR novas oportunidades — quando identificares uma call relevante para a equipa
   (robótica, UAV, IA, IoT, sensores, monitorização) que não esteja na lista, usa add_call
   com todos os campos que conseguires preencher.
   Estado: 'radar' se ainda não aberta; 'aberta' se já tem prazo ativo.

3. REGISTAR notas — após cada pesquisa ou análise, usa add_note com data e fonte.
   Prefere factos concretos; evita opiniões sem base.

4. ALERTAR prazos — sinaliza calls com prazo nos próximos 30 dias.

5. Responde sempre em português europeu. Confirma título e ID após cada escrita.";

      $instrucoesEN = "You are a funding opportunity scout for the TRIBE team at INESC TEC.
Team skills: mobile robotics, UAV (drones), computer vision, AI/ML, IoT, agricultural/forest monitoring, environmental sensors, telemetry, edge computing.

## API Access
Endpoint: {$apiEndpoint}
Authentication header: X-Api-Key: {$key}

GET {$apiEndpoint}                        → list all opportunities (JSON)
GET {$apiEndpoint}?estado=aberta          → filter by state (aberta/radar/fechada/arquivada)
GET {$apiEndpoint}?prioridade=A           → filter by priority (A/B/C)

Each opportunity has fields:
  id, titulo, url, prioridade (A/B/C), estado, area, encaixe, financiamento,
  duracao, datas (deadline), elegibilidade, probabilidade, proximo_passo, notes[]

## Write actions (POST {$apiEndpoint})
Required headers: X-Api-Key: {$key}  |  Content-Type: application/json

Add note:
  {\"action\":\"add_note\", \"call_id\":ID, \"note_text\":\"text\"}

Update an existing opportunity:
  {\"action\":\"update_call\", \"call_id\":ID, \"prioridade\":\"A\", \"estado\":\"aberta\",
   \"proximo_passo\":\"...\", \"probabilidade\":\"...\", \"datas\":\"deadline: dd/mm/yyyy\", ...}
  Editable fields: titulo, url, prioridade, area, encaixe, tipo_trl, financiamento,
  projetos_apoiados, duracao, datas, elegibilidade, sucesso_anteriores, probabilidade,
  proximo_passo, fontes, estado, notas_internas

Add a new opportunity:
  {\"action\":\"add_call\", \"titulo\":\"Call name\", \"url\":\"https://...\",
   \"prioridade\":\"B\", \"estado\":\"radar\", \"area\":\"...\", \"datas\":\"deadline: dd/mm/yyyy\"}
  Only titulo is required; all other fields are optional.

## Mandatory behaviour
1. UPDATE existing data — whenever you find more recent information about a call already
   in the list (new deadline, budget, URL, eligibility), call update_call immediately.
   Never leave stale data in the system.

2. ADD new opportunities — whenever you identify a relevant call for the team
   (robotics, UAV, AI, IoT, sensors, monitoring) not yet in the list, call add_call
   with every field you can populate.
   Use estado=radar if not yet open; estado=aberta if the deadline is already active.

3. LOG notes — after each research session or analysis, use add_note with date and source.
   Prefer concrete facts; avoid unsupported opinions.

4. ALERT on deadlines — flag calls with deadlines within 30 days.

5. Always respond in European Portuguese. Confirm title and ID after every write.";

      $prompts = [
        ['icon'=>'🤖','label'=>'Claude Project — Instruções completas','nota'=>'Cole em Settings → Project Instructions. Inclui endpoint, chave e todas as instruções.','text'=> $instrucoesPT],
        ['icon'=>'⚙️','label'=>'Claude Project — MCP Config (ligação à API)','nota'=>'Cole em Settings → Integrations → MCP. Necessário para que o Claude aceda à API diretamente.','text'=>
'{"mcpServers":{"tribe-opportunities":{"type":"http","url":"' . $apiEndpoint . '","headers":{"X-Api-Key":"' . $key . '"}}}}'],
        ['icon'=>'🟢','label'=>'Custom GPT — Instruções + Schema OpenAPI completo','nota'=>'Cole em Configure → Instructions. O schema OpenAPI para Actions está incluído no final — copia-o separadamente para Configure → Actions → Schema, e define Authentication → API Key → Header: X-Api-Key.','text'=>
$instrucoesEN . "\n\n" .
'---
# OpenAPI Schema — paste separately in Configure → Actions → Schema
# Then set Authentication: API Key | Header Name: X-Api-Key | Value: ' . $key . "

$openApiSchema"],
      ];
    ?>
    <div class="border rounded">
      <!-- Cabeçalho da chave -->
      <div class="d-flex align-items-center gap-2 px-3 py-2 bg-light rounded-top"
           style="cursor:pointer" onclick="document.getElementById('<?= $skPanelId ?>').classList.toggle('d-none')">
        <i class="bi bi-key-fill text-warning"></i>
        <span class="fw-semibold"><?= htmlspecialchars($name) ?></span>
        <span class="text-muted small ms-1">· <?= htmlspecialchars($sk['username'] ?? '') ?></span>
        <span class="text-muted small ms-auto me-2">último uso: <?= $sk['last_used_at'] ? date('d/m/Y H:i', strtotime($sk['last_used_at'])) : 'nunca' ?></span>
        <form method="post" class="d-inline" onsubmit="return confirm('Revogar a chave \'<?= htmlspecialchars($name) ?>\'?')">
          <input type="hidden" name="fc_action" value="revoke_scout_key">
          <input type="hidden" name="subtab" value="exploracao">
          <input type="hidden" name="key_id" value="<?= $sk['id'] ?>">
          <button class="btn btn-sm btn-outline-danger py-0 px-2" type="submit" onclick="event.stopPropagation()">Revogar</button>
        </form>
        <i class="bi bi-chevron-<?= $autoOpen ? 'up' : 'down' ?> text-muted"></i>
      </div>
      <!-- Prompts da chave -->
      <div id="<?= $skPanelId ?>" class="<?= $autoOpen ? '' : 'd-none' ?> p-3">
        <p class="text-muted small mb-3">Instrui o teu assistente com um destes blocos. Os blocos <strong>sem chave</strong> vão nas instruções do sistema; os <strong>com chave</strong> vão na configuração de autenticação/integração.</p>
        <div class="row g-2">
        <?php foreach ($prompts as $p): ?>
        <div class="col-12 col-lg-6">
          <div class="border rounded p-2 h-100">
            <div class="d-flex justify-content-between align-items-center mb-1">
              <strong style="font-size:0.85rem"><?= $p['icon'] ?> <?= htmlspecialchars($p['label']) ?></strong>
              <button class="btn btn-sm btn-outline-secondary py-0 px-2" style="font-size:0.75rem"
                      onclick="navigator.clipboard.writeText(this.closest('.border').querySelector('pre').textContent).then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='Copiar',1500)})">Copiar</button>
            </div>
            <div class="text-muted mb-1" style="font-size:0.75rem"><?= htmlspecialchars($p['nota']) ?></div>
            <pre class="bg-light rounded p-2 mb-0" style="font-size:0.72rem;white-space:pre-wrap;max-height:220px;overflow:auto"><?= htmlspecialchars($p['text']) ?></pre>
          </div>
        </div>
        <?php endforeach; ?>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="text-muted small mb-0">Sem chaves criadas. Cria uma chave para ligar um assistente de IA.</p>
    <?php endif; ?>
  </div>
</div>

<!-- Filtros -->
<div class="d-flex gap-2 mb-3 flex-wrap align-items-center">
  <input type="text" id="fc-search" class="form-control form-control-sm" placeholder="Pesquisar título ou área..." style="max-width:260px" oninput="fcFilter()">
  <select id="fc-filter-prio" class="form-select form-select-sm" style="max-width:160px" onchange="fcFilter()">
    <option value="">Todas prioridades</option>
    <option value="A">A — Principal</option>
    <option value="B">B — Secundária</option>
    <option value="C">C — Radar</option>
  </select>
  <select id="fc-filter-estado" class="form-select form-select-sm" style="max-width:160px" onchange="fcFilter()">
    <option value="">Todos os estados</option>
    <option value="aberta">Aberta</option>
    <option value="radar">Radar</option>
    <option value="fechada">Fechada</option>
    <option value="arquivada">Arquivada</option>
  </select>
  <small class="text-muted ms-auto" id="fc-count-label"><?= count($allCalls) ?> oportunidades</small>
</div>

<!-- Lista de cards -->
<div id="fc-cards-container">
<?php foreach ($allCalls as $fc):
    $notes = $allNotes[$fc['id']] ?? [];
    $fcId = 'fc-card-' . $fc['id'];
    $fcDeadline = fcExtractDeadline($fc['datas'] ?? '');
    $now = new DateTime();
    $daysLeft = $fcDeadline ? (int)$now->diff($fcDeadline)->format('%r%a') : null;
    if ($fcDeadline && $daysLeft !== null) {
        if ($daysLeft < 0) $deadlineBadge = '<span class="badge bg-secondary ms-1" title="' . htmlspecialchars($fc['datas']) . '"><i class="bi bi-calendar-x me-1"></i>Encerrado</span>';
        elseif ($daysLeft <= 7) $deadlineBadge = '<span class="badge bg-danger ms-1" title="' . htmlspecialchars($fc['datas']) . '"><i class="bi bi-alarm me-1"></i>' . $fcDeadline->format('d/m/Y') . ' (' . $daysLeft . 'd)</span>';
        elseif ($daysLeft <= 30) $deadlineBadge = '<span class="badge bg-warning text-dark ms-1" title="' . htmlspecialchars($fc['datas']) . '"><i class="bi bi-calendar-event me-1"></i>' . $fcDeadline->format('d/m/Y') . ' (' . $daysLeft . 'd)</span>';
        else $deadlineBadge = '<span class="badge bg-light text-dark border ms-1" title="' . htmlspecialchars($fc['datas']) . '"><i class="bi bi-calendar me-1"></i>' . $fcDeadline->format('d/m/Y') . '</span>';
    } else {
        $deadlineBadge = '';
    }
?>
<div class="fc-card-item mb-2"
     data-titulo="<?= strtolower(htmlspecialchars($fc['titulo'] . ' ' . $fc['area'])) ?>"
     data-prio="<?= $fc['prioridade'] ?>"
     data-estado="<?= $fc['estado'] ?>">
  <div class="card border-0 shadow-sm">
    <div class="card-header d-flex align-items-center gap-2 py-2 bg-transparent border-bottom" style="cursor:pointer" onclick="document.getElementById('<?= $fcId ?>').classList.toggle('d-none')">
      <?= $pBadge[$fc['prioridade']] ?? '' ?>
      <?= $estadoBadge[$fc['estado']] ?? '' ?>
      <span class="fw-semibold flex-grow-1"><?= htmlspecialchars($fc['titulo']) ?></span>
      <?php if ($fc['url']): ?>
        <a href="<?= htmlspecialchars($fc['url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary btn-sm py-0 px-2" onclick="event.stopPropagation()" title="Abrir call" style="font-size:0.75rem"><i class="bi bi-box-arrow-up-right me-1"></i>Call</a>
      <?php endif; ?>
      <?= $deadlineBadge ?>
      <span class="badge bg-light text-dark border"><?= count($notes) ?> <i class="bi bi-chat-dots"></i></span>
    </div>
    <div id="<?= $fcId ?>" class="d-none">
      <div class="card-body pb-2">
        <!-- Campos principais em grid -->
        <div class="row g-2 mb-3">
          <?php
          $fields = [
            ['Área / Oportunidade', $fc['area']],
            ['Encaixe TRIBE', $fc['encaixe']],
            ['Tipo e TRL', $fc['tipo_trl']],
            ['Financiamento', $fc['financiamento']],
            ['Projetos apoiados', $fc['projetos_apoiados']],
            ['Duração', $fc['duracao']],
            ['Datas e prazo', $fc['datas']],
            ['Elegibilidade', $fc['elegibilidade']],
            ['Sucesso anteriores', $fc['sucesso_anteriores']],
            ['Probabilidade TRIBE', $fc['probabilidade']],
            ['Próximo passo', $fc['proximo_passo']],
            ['Fontes', $fc['fontes']],
          ];
          foreach ($fields as [$label, $val]):
            if (!trim($val)) continue; ?>
          <div class="col-12 col-md-6">
            <div class="bg-light rounded p-2" style="font-size:0.85rem">
              <div class="text-muted" style="font-size:0.75rem"><?= $label ?></div>
              <div><?= nl2br(htmlspecialchars($val)) ?></div>
            </div>
          </div>
          <?php endforeach; ?>
          <?php if (trim($fc['notas_internas'] ?? '')): ?>
          <div class="col-12">
            <div class="bg-warning bg-opacity-10 border border-warning rounded p-2" style="font-size:0.85rem">
              <div class="text-muted" style="font-size:0.75rem"><i class="bi bi-sticky me-1"></i>Notas internas</div>
              <div><?= nl2br(htmlspecialchars($fc['notas_internas'])) ?></div>
            </div>
          </div>
          <?php endif; ?>
        </div>

        <!-- Notas/comentários da equipa -->
        <div class="border-top pt-2 mb-2">
          <div class="fw-semibold small mb-2"><i class="bi bi-chat-dots me-1"></i>Notas da equipa (<?= count($notes) ?>)</div>
          <?php if ($notes): ?>
          <div class="fc-notes-list" style="max-height:200px;overflow-y:auto">
            <?php foreach ($notes as $n): ?>
            <div class="d-flex gap-2 mb-2">
              <div class="rounded-circle bg-secondary text-white d-flex align-items-center justify-content-center flex-shrink-0" style="width:28px;height:28px;font-size:0.7rem"><?= strtoupper(substr($n['username'] ?? '?', 0, 1)) ?></div>
              <div class="flex-grow-1">
                <div class="bg-light rounded p-2" style="font-size:0.85rem"><?= nl2br(htmlspecialchars($n['note_text'])) ?></div>
                <small class="text-muted"><?= htmlspecialchars($n['username'] ?? 'anon') ?> · <?= date('d/m/Y H:i', strtotime($n['created_at'])) ?></small>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php else: ?><p class="text-muted small">Sem notas ainda.</p><?php endif; ?>

          <!-- Adicionar nota -->
          <form method="post" class="mt-2 d-flex gap-2">
            <input type="hidden" name="fc_action" value="add_note">
            <input type="hidden" name="subtab" value="exploracao">
            <input type="hidden" name="call_id" value="<?= $fc['id'] ?>">
            <textarea name="note_text" class="form-control form-control-sm" rows="2" placeholder="Adicionar nota ou comentário..."></textarea>
            <button type="submit" class="btn btn-sm btn-primary align-self-end">Enviar</button>
          </form>
        </div>

        <!-- Botões de ação -->
        <div class="d-flex gap-2 border-top pt-2 flex-wrap align-items-center">
          <button class="btn btn-sm btn-outline-secondary" onclick="fcOpenEdit(<?= $fc['id'] ?>)"><i class="bi bi-pencil me-1"></i>Editar</button>
          <?php if ($fc['url']): ?>
          <a href="<?= htmlspecialchars($fc['url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary"><i class="bi bi-box-arrow-up-right me-1"></i>Abrir Call</a>
          <?php endif; ?>
          <?php if ($fc['lead_id']): ?>
          <a href="?tab=leads&subtab=leads&lead_id=<?= (int)$fc['lead_id'] ?>" class="btn btn-sm btn-success"><i class="bi bi-people-fill me-1"></i>Ver Lead</a>
          <?php else: ?>
          <form method="post" class="d-inline" onsubmit="return confirm('Criar lead a partir desta oportunidade?')">
            <input type="hidden" name="fc_action" value="convert_to_lead">
            <input type="hidden" name="subtab" value="exploracao">
            <input type="hidden" name="call_id" value="<?= $fc['id'] ?>">
            <button type="submit" class="btn btn-sm btn-outline-success"><i class="bi bi-people me-1"></i>Converter em Lead</button>
          </form>
          <?php endif; ?>
          <!-- Arquivar / Apagar -->
          <div class="ms-auto d-flex gap-1">
            <?php if ($fc['estado'] !== 'arquivada'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Arquivar esta oportunidade?')">
              <input type="hidden" name="fc_action" value="archive_call">
              <input type="hidden" name="subtab" value="exploracao">
              <input type="hidden" name="call_id" value="<?= $fc['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary" title="Arquivar"><i class="bi bi-archive"></i></button>
            </form>
            <?php endif; ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Eliminar definitivamente «<?= htmlspecialchars(addslashes($fc['titulo'])) ?>»?\n\nEsta ação não pode ser desfeita.')">
              <input type="hidden" name="fc_action" value="delete_call">
              <input type="hidden" name="subtab" value="exploracao">
              <input type="hidden" name="call_id" value="<?= $fc['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="Eliminar"><i class="bi bi-trash"></i></button>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>
<?php endforeach; ?>
</div><!-- /fc-cards-container -->

</div><!-- /fc-section -->
</div><!-- /mt-5 -->

<!-- ─── Modal: Adicionar / Editar Oportunidade ──────────────────────────────── -->
<div class="modal fade" id="fcAddModal" tabindex="-1" aria-labelledby="fcModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-xl modal-dialog-scrollable" style="max-height:90vh">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="fcModalTitle">Nova Oportunidade</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <form method="post" id="fcAddForm">
        <input type="hidden" name="fc_action" id="fc_form_action" value="add_call">
        <input type="hidden" name="subtab" value="exploracao">
        <input type="hidden" name="call_id" id="fc_form_call_id" value="">
        <div class="modal-body">
          <div class="row g-3">
            <div class="col-12 col-md-8">
              <label class="form-label">Título <span class="text-danger">*</span></label>
              <input type="text" name="titulo" id="fc_f_titulo" class="form-control" required>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Prioridade</label>
              <select name="prioridade" id="fc_f_prioridade" class="form-select">
                <option value="A">A — Principal</option>
                <option value="B" selected>B — Secundária</option>
                <option value="C">C — Radar</option>
              </select>
            </div>
            <div class="col-6 col-md-2">
              <label class="form-label">Estado</label>
              <select name="estado" id="fc_f_estado" class="form-select">
                <option value="aberta">Aberta</option>
                <option value="radar">Radar</option>
                <option value="fechada">Fechada</option>
                <option value="arquivada">Arquivada</option>
              </select>
            </div>
            <div class="col-12">
              <label class="form-label">URL oficial</label>
              <input type="url" name="url" id="fc_f_url" class="form-control" placeholder="https://...">
            </div>
            <div class="col-12">
              <label class="form-label">Etiqueta de prioridade (ex: "A — Seleção principal — Aberto")</label>
              <input type="text" name="prioridade_label" id="fc_f_prioridade_label" class="form-control">
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Área / Oportunidade</label>
              <textarea name="area" id="fc_f_area" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Encaixe TRIBE / Papel</label>
              <textarea name="encaixe" id="fc_f_encaixe" class="form-control" rows="3"></textarea>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Tipo e TRL</label>
              <textarea name="tipo_trl" id="fc_f_tipo_trl" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Financiamento</label>
              <textarea name="financiamento" id="fc_f_financiamento" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Projetos apoiados</label>
              <input type="text" name="projetos_apoiados" id="fc_f_projetos_apoiados" class="form-control">
            </div>
            <div class="col-12 col-md-4">
              <label class="form-label">Duração</label>
              <textarea name="duracao" id="fc_f_duracao" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-8">
              <label class="form-label">Datas e prazo</label>
              <textarea name="datas" id="fc_f_datas" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Elegibilidade / Consórcio</label>
              <textarea name="elegibilidade" id="fc_f_elegibilidade" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Sucesso nas últimas 3 edições</label>
              <textarea name="sucesso_anteriores" id="fc_f_sucesso_anteriores" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12 col-md-6">
              <label class="form-label">Probabilidade TRIBE</label>
              <textarea name="probabilidade" id="fc_f_probabilidade" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Próximo passo</label>
              <textarea name="proximo_passo" id="fc_f_proximo_passo" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Fontes documentais / verificação</label>
              <textarea name="fontes" id="fc_f_fontes" class="form-control" rows="2"></textarea>
            </div>
            <div class="col-12">
              <label class="form-label">Notas internas</label>
              <textarea name="notas_internas" id="fc_f_notas_internas" class="form-control" rows="2" placeholder="Notas de gestão interna (não visíveis a assistentes externos)"></textarea>
            </div>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancelar</button>
          <button type="submit" class="btn btn-primary" id="fcSubmitBtn">Guardar</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- ─── Modal: Prompts para IA Scout ──────────────────────────────────────────── -->
<div class="modal fade" id="fcScoutPromptsModal" tabindex="-1">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title">Prompts para IA Scout</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body" id="fcScoutPromptsBody"></div>
    </div>
  </div>
</div>

<script>
// ─── Dados PHP para JS ──────────────────────────────────────────────────────
const FC_CALLS = <?= json_encode(array_values($callsById), JSON_UNESCAPED_UNICODE) ?>;
const FC_CALLS_MAP = {};
FC_CALLS.forEach(c => FC_CALLS_MAP[c.id] = c);

// ─── Filtrar cards ──────────────────────────────────────────────────────────
function fcFilter() {
    const q = document.getElementById('fc-search').value.toLowerCase();
    const prio = document.getElementById('fc-filter-prio').value;
    const estado = document.getElementById('fc-filter-estado').value;
    let count = 0;
    document.querySelectorAll('.fc-card-item').forEach(el => {
        const t = el.dataset.titulo;
        const p = el.dataset.prio;
        const e = el.dataset.estado;
        // Sem filtro de estado: oculta arquivadas por defeito
        const estadoMatch = estado ? e === estado : e !== 'arquivada';
        const show = (!q || t.includes(q)) && (!prio || p === prio) && estadoMatch;
        el.style.display = show ? '' : 'none';
        if (show) count++;
    });
    const hasArchived = document.querySelectorAll('.fc-card-item[data-estado="arquivada"]').length;
    const label = document.getElementById('fc-count-label');
    label.textContent = count + ' oportunidades';
    if (!estado && hasArchived) {
        label.innerHTML = count + ' oportunidades <a href="#" class="ms-2 text-muted small" onclick="document.getElementById(\'fc-filter-estado\').value=\'arquivada\';fcFilter();return false">+ ' + hasArchived + ' arquivada' + (hasArchived>1?'s':'') + '</a>';
    }
}

// ─── Abrir modal de adicionar ───────────────────────────────────────────────
function fcShowAdd() {
    document.getElementById('fcModalTitle').textContent = 'Nova Oportunidade';
    document.getElementById('fc_form_action').value = 'add_call';
    document.getElementById('fc_form_call_id').value = '';
    document.getElementById('fcSubmitBtn').textContent = 'Adicionar';
    ['titulo','url','prioridade_label','area','encaixe','tipo_trl','financiamento',
     'projetos_apoiados','duracao','datas','elegibilidade','sucesso_anteriores',
     'probabilidade','proximo_passo','fontes','notas_internas'].forEach(f => {
        const el = document.getElementById('fc_f_' + f);
        if (el) el.value = '';
    });
    document.getElementById('fc_f_prioridade').value = 'B';
    document.getElementById('fc_f_estado').value = 'aberta';
    new bootstrap.Modal(document.getElementById('fcAddModal')).show();
}

// ─── Abrir modal de editar ──────────────────────────────────────────────────
function fcOpenEdit(id) {
    const c = FC_CALLS_MAP[id];
    if (!c) return;
    document.getElementById('fcModalTitle').textContent = 'Editar: ' + c.titulo;
    document.getElementById('fc_form_action').value = 'edit_call';
    document.getElementById('fc_form_call_id').value = c.id;
    document.getElementById('fcSubmitBtn').textContent = 'Guardar alterações';
    const fields = ['titulo','url','prioridade_label','area','encaixe','tipo_trl','financiamento',
     'projetos_apoiados','duracao','datas','elegibilidade','sucesso_anteriores',
     'probabilidade','proximo_passo','fontes','notas_internas'];
    fields.forEach(f => {
        const el = document.getElementById('fc_f_' + f);
        if (el) el.value = c[f] ?? '';
    });
    document.getElementById('fc_f_prioridade').value = c.prioridade || 'B';
    document.getElementById('fc_f_estado').value = c.estado || 'aberta';
    new bootstrap.Modal(document.getElementById('fcAddModal')).show();
}

// ─── Prompts para IA Scout ──────────────────────────────────────────────────
function fcShowScoutPrompts(key, name) {
    const baseUrl = window.location.origin;
    const prompts = [
        {
            icon: '🤖',
            label: 'Claude Project — Instruções (sem chave)',
            nota: 'Cole em Settings → Project Instructions do Claude Project.',
            text: `És um assistente especializado no scouting de oportunidades de financiamento para a equipa TRIBE do INESC TEC.

A equipa tem competências em: robótica móvel, UAV, visão computacional, IA, IoT, monitorização agrícola/florestal, sensores ambientais.

Para acederes às oportunidades atuais usa o endpoint:
GET ${baseUrl}/api/opportunities_context.php

Este endpoint devolve a lista de calls em JSON. Com base nesses dados podes:
- Identificar novas oportunidades relevantes
- Sugerir próximos passos para cada call
- Alertar para prazos próximos
- Propor notas via API (endpoint de POST /api/opportunities_context.php)

Responde sempre em português europeu. Sê conciso e orientado para ação.`
        },
        {
            icon: '🔑',
            label: 'Claude Project — MCP Config (com chave)',
            nota: 'Cole em Settings → Integrations → MCP do Claude Project.',
            text: `{
  "mcpServers": {
    "tribe-opportunities": {
      "type": "http",
      "url": "${baseUrl}/api/opportunities_context.php",
      "headers": {
        "X-Api-Key": "${key}"
      }
    }
  }
}`
        },
        {
            icon: '🟢',
            label: 'Custom GPT — System Prompt (sem chave)',
            nota: 'Cole em Configure → Instructions do Custom GPT.',
            text: `You are a funding opportunity scout for the TRIBE team at INESC TEC.
Team expertise: mobile robotics, UAV, computer vision, AI, IoT, agricultural/forest monitoring, environmental sensors.

Use the action "getOpportunities" to fetch the current list of funding calls and provide analysis in European Portuguese.
Focus on: deadlines, fit with team skills, priority level, and recommended next steps.`
        },
        {
            icon: '🔑',
            label: 'Custom GPT — Action Authentication',
            nota: 'Cole no campo "API Key" em Configure → Actions → Authentication → API Key.',
            text: key
        },
        {
            icon: '⚙️',
            label: 'n8n / Make — HTTP Request (com chave)',
            nota: 'Configure um nó HTTP Request com estes parâmetros.',
            text: `URL: ${baseUrl}/api/opportunities_context.php
Method: GET
Headers:
  X-Api-Key: ${key}
  Content-Type: application/json

// Para adicionar nota a uma oportunidade:
// Method: POST
// Body (JSON): {"action":"add_note","call_id":1,"note_text":"Nota do assistente"}`
        },
        {
            icon: '💻',
            label: 'cURL (com chave)',
            nota: 'Execute no terminal para testar a ligação.',
            text: `# Listar oportunidades
curl -H "X-Api-Key: ${key}" "${baseUrl}/api/opportunities_context.php"

# Adicionar nota a uma oportunidade (call_id=1)
curl -X POST \\
  -H "X-Api-Key: ${key}" \\
  -H "Content-Type: application/json" \\
  -d '{"action":"add_note","call_id":1,"note_text":"Verificado pelo assistente IA"}' \\
  "${baseUrl}/api/opportunities_context.php"`
        }
    ];

    let html = `<p class="text-muted small mb-3">Chave: <code>${escH(name)}</code> — Partilha estas instruções com o teu assistente de IA.</p>`;
    prompts.forEach(p => {
        html += `<div class="mb-3">
          <div class="d-flex justify-content-between align-items-center mb-1">
            <strong>${p.icon} ${escH(p.label)}</strong>
            <button class="btn btn-sm btn-outline-secondary" onclick="navigator.clipboard.writeText(${JSON.stringify(p.text)})">Copiar</button>
          </div>
          <div class="text-muted small mb-1">${escH(p.nota)}</div>
          <pre class="bg-light p-2 rounded small" style="white-space:pre-wrap;max-height:160px;overflow:auto">${escH(p.text)}</pre>
        </div>`;
    });

    document.getElementById('fcScoutPromptsBody').innerHTML = html;
    new bootstrap.Modal(document.getElementById('fcScoutPromptsModal')).show();
}

function escH(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Mover modais para o body para evitar problemas de overflow/stacking em containers com altura fixa
document.addEventListener('DOMContentLoaded', function() {
    ['fcAddModal','fcScoutPromptsModal'].forEach(function(id) {
        var el = document.getElementById(id);
        if (el && el.parentElement !== document.body) {
            document.body.appendChild(el);
        }
    });
    fcFilter();
});
</script>
