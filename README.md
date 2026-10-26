<p align="center">
  <img src="mini-erp-web/public/assets/images/gif_logo.png" alt="MiniERP" width="360">
</p>

<p align="center">ERP web multiempresa em PHP + MariaDB — pedidos, documentos fiscais e multi-tenant.</p>

<p align="center">
  <img alt="PHP 8.2" src="https://img.shields.io/badge/PHP-8.2-777BB4?logo=php&logoColor=white">
  <img alt="MariaDB" src="https://img.shields.io/badge/MariaDB-MySQL-003545?logo=mariadb&logoColor=white">
  <img alt="Status" src="https://img.shields.io/badge/status-em%20desenvolvimento-F59E0B">
</p>

## Visão geral

MiniERP é uma aplicação web para operações comerciais (pedidos) e preparação fiscal de NF-e/NFC-e. Utiliza arquitetura multi-tenant com separação de dados por banco, fluxos transacionais e prévias locais de DANFE/DANFC-e.

## Funcionalidades

- **Multi-tenant com banco por cliente:** isolamento de dados entre empresas em um mesmo deploy.
- **Cadastro unificado:** pessoas, produtos e transportadoras.
- **Pedidos:** itens, frete, volumes e totais; pedido e documento fiscal interno gravados em uma única transação.
- **Central de Notas:** listagem de estados e eventos, timeline, XML, reprocessamento controlado e envio (quando configurado).
- **Prévia DANFE (55) em A4 e DANFC-e (65) compacta:** geradas localmente em PDF, sem certificado e sem transmissão à SEFAZ.
- **Alocador de números fiscais:** controle de séries e faixas de numeração por empresa, evitando colisões.
- **Armazenamento de XML com integridade:** hash SHA-256 para verificação.
- **Catálogos e importadores:** sincronização de NCM e plano contábil; importação de CSV/XLS para produtos, NCMs e contas.
- **Exportação contábil:** planilhas e relatórios prontos para a contabilidade.
- **Estoque:** alertas de nível crítico e reserva de estoque por pedido.
- **Permissões:** roles por função e painel de plataforma para provisionamento de empresas.
- **API:** endpoints para criação de pedidos e sincronização de estoque (POS, marketplaces).

## Screenshots

**Login**

<p>
  <img src="mini-erp-web/prints/DESKTOP.jpg" alt="Login desktop" width="560">
  <img src="mini-erp-web/prints/mobile.jpg" alt="Login mobile" width="180">
</p>

**Dashboard** — KPIs, filtros por período, faturamento e produtos mais vendidos.

<p>
  <img src="mini-erp-web/prints/dashboard%20desktop.jpg" alt="Dashboard desktop" width="560">
  <img src="mini-erp-web/prints/tablet%20dashboard.jpg" alt="Dashboard tablet" width="240">
  <img src="mini-erp-web/prints/mobile%20dashboard.jpg" alt="Dashboard mobile" width="180">
</p>

**Painel da Plataforma** — listagem de empresas, busca, status e provisionamento.

<p>
  <img src="mini-erp-web/prints/notebook.jpg" alt="Painel da plataforma desktop" width="560">
  <img src="mini-erp-web/prints/PAINEL%20MOBILE.jpg" alt="Painel da plataforma mobile" width="180">
</p>

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
