<p align="center">
  <img src="mini-erp-web/public/assets/images/gif_logo.png" alt="MiniERP" width="360">
</p>

<p align="center">ERP web multiempresa em PHP + MariaDB — pedidos, financeiro, documentos fiscais e multi-tenant.</p>

<p align="center">
  <img alt="PHP 8.2" src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white">
  <img alt="MariaDB" src="https://img.shields.io/badge/MariaDB-MySQL-003545?logo=mariadb&logoColor=white">
  <img alt="Status" src="https://img.shields.io/badge/status-em%20desenvolvimento-F59E0B">
</p>

## Visão geral

MiniERP é um sistema de gestão web que integra operação comercial, controle financeiro e preparação fiscal de NF-e/NFC-e em uma única aplicação. Foi construído em arquitetura multi-tenant, com banco de dados separado por empresa, fluxos transacionais e prévias locais de DANFE/DANFC-e.

## Módulos

| Módulo | Descrição |
|---|---|
| **Dashboard** | KPIs, filtros por período, faturamento e produtos mais vendidos |
| **Cadastros** | Pessoas (clientes/fornecedores), produtos e transportadoras em cadastro unificado |
| **Pedidos** | Itens, frete, volumes, totais e reserva de estoque |
| **Financeiro** | Controle financeiro integrado aos pedidos e à contabilidade |
| **Central de Notas** | Documentos fiscais, XML, timeline de eventos e reprocessamento |
| **Estoque** | Alertas de nível crítico e reserva por pedido |
| **Catálogos** | NCM e plano contábil, com importadores CSV/XLS |
| **Plataforma** | Provisionamento e administração de empresas (tenants) |

## Funcionalidades

### Arquitetura e acesso
- **Multi-tenant com banco por cliente:** isolamento total de dados entre empresas em um mesmo deploy.
- **Permissões por função:** roles granulares por usuário dentro de cada tenant.
- **Painel da plataforma:** criação, busca, status e acesso ao ERP de cada empresa.
- **Interface responsiva:** menu lateral no desktop e barra inferior no mobile.

### Comercial
- **Cadastro unificado:** pessoas, produtos e transportadoras compartilham a mesma base.
- **Pedidos transacionais:** o pedido e o documento fiscal interno são gravados em uma única transação, evitando divergência entre ambos.
- **Frete e volumes:** transportadora, volumes e totais calculados no pedido.
- **Estoque:** reserva ao confirmar o pedido e alertas de itens em nível crítico.

### Financeiro
- **Controle financeiro no módulo:** lançamentos financeiros ligados aos pedidos e cadastros.
- **Exportação contábil:** planilhas e relatórios prontos para envio à contabilidade.
- **Importação de contas:** carga de plano contábil via CSV/XLS.

### Fiscal
- **Central de Notas:** estados, eventos, timeline, XML e reprocessamento controlado de falhas.
- **Prévia DANFE (55) em A4 e DANFC-e (65) compacta:** geradas localmente em PDF, sem certificado e sem transmissão à SEFAZ.
- **Alocador de números fiscais:** controle de séries e faixas de numeração por empresa e ambiente (homologação/produção), evitando colisões.
- **XML com integridade:** armazenamento com hash SHA-256 para verificação posterior.
- **Transmissão:** envio à SEFAZ quando certificado A1 e ambiente estiverem configurados.

### Dados e integrações
- **Sincronização de catálogos:** NCM e contábil.
- **Importadores tabulares:** CSV/XLS para produtos, NCMs e contas.
- **API:** endpoints para criação de pedidos e sincronização de estoque (POS, marketplaces).

## Screenshots

### Login — desktop

<img src="mini-erp-web/prints/DESKTOP.jpg" alt="Login desktop" width="800">

### Login — mobile

<img src="mini-erp-web/prints/mobile.jpg" alt="Login mobile" width="300">

### Dashboard — desktop

<img src="mini-erp-web/prints/dashboard%20desktop.jpg" alt="Dashboard desktop" width="800">

### Dashboard — tablet

<img src="mini-erp-web/prints/tablet%20dashboard.jpg" alt="Dashboard tablet" width="500">

### Dashboard — mobile

<img src="mini-erp-web/prints/mobile%20dashboard.jpg" alt="Dashboard mobile" width="300">

### Painel da Plataforma — desktop

<img src="mini-erp-web/prints/notebook.jpg" alt="Painel da plataforma desktop" width="800">

### Painel da Plataforma — mobile

<img src="mini-erp-web/prints/PAINEL%20MOBILE.jpg" alt="Painel da plataforma mobile" width="300">

## Requisitos

- PHP 8.2+
- MariaDB / MySQL
- Composer
- Apache (XAMPP) ou servidor embutido do PHP

## Instalação (local)

```powershell
cd C:\xampp\htdocs
git clone https://github.com/WillSPACCE/MiniERP-1.0.git MiniRP
cd MiniRP\mini-erp-web
composer install
```

Configure as credenciais do banco em `mini-erp-web/config.php` e inicie o servidor:

```powershell
C:\xampp\php\php.exe -S 127.0.0.1:8000 -t public
```

Acesse `http://127.0.0.1:8000/`.

## Notas fiscais

- As prévias de DANFE/DANFC-e são locais e não realizam transmissão.
- A transmissão exige certificado A1, série e ambiente SEFAZ configurados.

## Licença

Consulte o `composer.json`. Contate o autor antes de redistribuir.

---

Desenvolvido por [WillSPACCE](https://github.com/WillSPACCE).
