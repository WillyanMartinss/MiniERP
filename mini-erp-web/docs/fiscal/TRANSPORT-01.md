# Transporte: cadastro e conferência local

## Entrega e limites

Nova área `?page=transporte`, com CT-e (57), MDF-e (58), veículos e configurações próprias. O antigo atalho MDF-e saiu de Configuração. A área utiliza o menu existente, inclusive seus modos compacto e móvel.

Escopo implementado: rascunhos rodoviários, edição, conferência de dados, histórico de revisões e preenchimento inicial a partir de pedido. O resultado `VALIDATED_LOCAL` significa somente conferência local. Não representa documento fiscal autorizado, prontidão fiscal completa ou validação de XML.

Não implementados: XML CT-e/MDF-e, XSD, assinatura, numeração fiscal definitiva, transmissão/consulta SEFAZ, DACTE/DAMDFE, cancelamento, encerramento, contingência e regras completas por UF/tributação/modal. Os indicadores `xml_validated`, `signature_validated` e `sefaz_authorized` permanecem falsos. Não há chamadas à SEFAZ nem leitura de segredos do certificado neste módulo.

## Reaproveitamento e contrato de persistência

- Emitente: `TenantEstablishmentRepository`, mesma empresa autenticada.
- Participantes, transportadora e condutor: cadastros existentes `clientes`, `transportadoras`, `motoristas`; sem duplicar o cadastro no MAIN.
- Pedido: `fiscal_orders` e `fiscal_order_transport_details`, sempre filtrados pela empresa. Importação preenche somente dados disponíveis; não inventa chave NF-e ou tributação.
- RNTRC e séries sugeridas: `transport_settings.payload_json`, por empresa. Não reserva numeração.
- Veículos: `transport_vehicles`, por empresa, placa única nessa empresa.

Para cada campo definido em `TransportFields::groups(57|58)`, o contrato é: entrada no formulário gerado por essa mesma definição → `TransportFields::normalize` → `transport_documents.payload_json.<nome_do_campo>` → `TransportRepository::find()['payload']` → formulário de edição. Campos desconhecidos não são persistidos. As definições são o catálogo de campos para os dois modelos; `TransportRepositoryMariaDbTest` compara o payload completo após inserção e edição.

Campos digitados são `USER_INPUT`. Série e RNTRC podem iniciar por `CONFIGURATION`; importação de pedido e emitente são `DERIVED`, e o usuário pode revisar os campos importados. Modelo, ambiente de homologação, modal rodoviário e tipo normal são definidos no backend. Prévia de ICMS é `CALCULATED`, não substitui motor tributário. CST, classificação e alíquotas informadas são provisórias e não significam regra tributária resolvida.

Os registros relacionados são copiados para `snapshot_json` ao salvar: emitente, participantes selecionados, transportadora, condutores/veículos selecionados e pedido. O payload guarda os demais valores documentais. A conferência fica em `validation_json`; eventos guardam revisão, ator, ação, data e resultado. Estes snapshots são de rascunho: nova gravação atualiza a cópia, e não existe documento autorizado editável neste fluxo.

## Grupos e destino fiscal

| Campos/grupo | Conferência local | Destino XML |
| --- | --- | --- |
| Identificação, série, data, UF e municípios | Série, data, UF, formato IBGE e prefixo estadual | Pendente: identificação CT-e/MDF-e; nenhuma tag gerada |
| Pedido de origem, título | Referência existente na empresa; identificação interna | Operacionais, sem tag própria |
| Remetente, destinatário, expedidor, recebedor e tomador | Referências, CPF/CNPJ, endereço e campos condicionais | Pendente: participantes CT-e |
| CFOP, natureza, valores e componentes | CFOP doméstico de transporte, positivos, soma dos componentes, valor a receber | Pendente: prestação CT-e |
| ICMS, IBS/CBS e informações fiscais | Formato, faixas e condicionais básicos; prévia ICMS | Pendente: grupos tributários e regras fiscais completas |
| Viagem, UFs, veículo, reboque, condutores | Referências, placa/cadastro, CPF, CNH, percurso e datas | Pendente: modal rodoviário MDF-e |
| Contratante, CIOT, vale-pedágio e pagamento | Formato e condicionais básicos; detalhes textuais preservados | Pendente: informações ANTT/pagamento estruturadas |
| Produto, NCM, valor, peso, volumes e chaves | Positivos, formato NCM, chave de 44 dígitos, modelo, dígito verificador e duplicatas | Pendente: carga e documentos vinculados |
| RNTRC, seguro e averbações | Formato e preenchimentos condicionais básicos | Pendente: modal/seguro conforme modelo e leiaute |
| Transportadora operacional e observações | Referência da empresa e limite de texto | Mapeamento fiscal pendente; referência operacional não troca o emitente |

Nenhum campo desta entrega tem status de XML implementado. Obrigatoriedade local é centralizada em `TransportDocumentValidator`, varia conforme modelo e escolhas, e não substitui o conjunto completo de regras SEFAZ. CPF/CNPJ alfanumérico e cenários especiais precisam de implementação específica antes da emissão.

## Instalação e proteção

Migração aditiva `20260913_create_transport_documents.sql`, também incorporada ao template de novas empresas. Aplicada à Willyan Info (tenant 14) pelo serviço oficial de migrações, com backup prévio, operação `ce8329e26794d97e43f1bf5472c958e3`. Outras empresas precisam executar a migração pelo painel; a tela informa a pendência quando a estrutura não existe.

O repositório exige conexão `mini_erp_tenant_<id>` correspondente ao contexto autenticado. Novas tabelas usam filtro `tenant_id`; escritas exigem CSRF e permissão fiscal e respeitam modo somente leitura. Gravação de documento e evento é transacional. Revisão otimista impede sobrescrever edição concorrente; salvar rascunho limpa a conferência anterior.

## Verificação

- `TransportDocumentTest.php`: modelos, campos condicionais, decimais, chaves, participantes e ausência de autorização indevida.
- `TransportRepositoryMariaDbTest.php`: gravação/edição CT-e/MDF-e, payload e snapshot completos, frota, eventos, revisão concorrente e conexão da empresa. Opt-in `RUN_TRANSPORT_DB_TESTS=1`, `TRANSPORT_TEST_TENANT_ID`.
- `TransportHttpTest.php`: login real com usuário temporário, quatro abas, gravação/reabertura/conferência dos dois modelos e rejeição de CSRF. Opt-in `RUN_TRANSPORT_HTTP_TESTS=1`, `TRANSPORT_TEST_BASE`, `TRANSPORT_TEST_SLUG`, `TRANSPORT_TEST_TENANT_ID`. Remove somente os registros temporários criados pelo teste.
- Testes de template e interação do menu existente, além de lint PHP. Sem inspeção visual em navegador nesta etapa.

Referências para a próxima etapa de emissão: [documentação CT-e SVRS](https://dfe-portal.svrs.rs.gov.br/Cte/Documentos), [documentação MDF-e SVRS](https://dfe-portal.svrs.rs.gov.br/Mdfe/Documentos), [esquemas CT-e](https://www.cte.fazenda.gov.br/portal/listaConteudo.aspx?tipoConteudo=0xlG1bdBass%3D). A implementação fiscal deverá fixar e testar a versão de schema/notas técnicas aplicáveis antes de transmitir.
