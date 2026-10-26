# MiniERP

Sistema ERP multi-tenant em PHP para gestão operacional, controle fiscal e administração de empresas.

## Visão geral

O MiniERP foi estruturado para atender fluxos empresariais com foco em:

- cadastro de clientes, produtos e fornecedores
- controle de estoque e movimentações
- gestão financeira básica
- administração de múltiplos tenants
- documentos fiscais e operação de transporte
- painel administrativo da plataforma

A arquitetura foi organizada para separar camada de apresentação, regras de negócio, persistência e contexto de tenant.

## Funcionalidades

### Gestão operacional
- cadastro de clientes
- cadastro de produtos
- cadastro de fornecedores
- controle de estoque
- fluxo de vendas
- acompanhamento financeiro
- dashboard de visão geral

### Administração da plataforma
- multi-tenancy por empresa
- segmentação por tenant e contexto de dados
- gestão de usuários e permissões
- administração da empresa e ambiente
- acesso centralizado para operação da plataforma

### Fiscal e documentos
- documentos fiscais
- visualização de DANFE
- documentação fiscal e preview
- transporte de cargas
- CTe
- MDF-e
- geração e validação de artefatos fiscais

### Infraestrutura
- serviços de negócio
- repositórios de dados
- conexão por contexto de tenant
- migrações e versionamento do banco
- evolução modular do sistema

## Stack

- PHP 8+
- MySQL / MariaDB
- PDO
- Composer
- HTML, CSS e JavaScript
- arquitetura em camadas
- Apache
- XAMPP para desenvolvimento local

## Arquitetura

A aplicação segue uma estrutura funcional em camadas:

- frontend e páginas públicas
- serviços para regras de negócio
- repositories para persistência e consultas
- infraestrutura para tenant e conexão
- módulos específicos de documentos e fiscalização

Essa separação permite evoluir cada parte do sistema sem misturar regras de negócio com apresentação ou acesso direto ao banco.

## Estrutura do projeto

```text
mini-erp-web/
├── app/
├── bin/
├── database/
├── docs/
├── migrations/
├── public/
├── src/
├── tests/
├── composer.json
├── config.php
├── README.md
└── ...
```

## Módulos em destaque

- autenticação e autorização
- multi-tenant
- gestão comercial
- controle fiscal
- documentos de transporte
- CTe e MDF-e
- preview e artefatos fiscais
- administração central da plataforma

## Requisitos

- PHP 8+
- Composer
- MySQL ou MariaDB
- Apache ou ambiente equivalente
- XAMPP recomendado para desenvolvimento local
- VS Code

## Execução local

### Clonar o projeto

```bash
git clone <url-do-repositorio>
cd mini-erp-web
```

### Instalar dependências

```bash
composer install
```

### Configurar ambiente

Ajustar os dados de conexão no arquivo de configuração do projeto, conforme o ambiente local.

### Rodar a aplicação

Com XAMPP:

- iniciar Apache
- iniciar MySQL
- acessar a aplicação pela pasta pública do projeto

Ou com PHP embutido:

```bash
php -S 127.0.0.1:8000 -t public
```

## Banco de dados e migrações

O sistema utiliza um modelo de banco com suporte a múltiplos tenants e migrações estruturadas. Os arquivos principais encontram-se em:

- `database/`
- `migrations/`

Essa abordagem permite evoluir o esquema de forma controlada e compatível com o restante da aplicação.

## Testes

A área de testes está localizada em:

- `tests/`

Os testes cobrem autenticação, visibilidade por tenant, login, fluxos fiscais e módulos de transporte.

## Roadmap

### Implementado
- multi-tenancy
- administração da plataforma
- contexto de tenant e conexão
- autenticação e permissões
- controle fiscal e transporte
- CTe e MDF-e
- previews de documentos fiscais

### Em evolução
- refinamento de regras fiscais
- expansão financeira e contábil
- melhoria de dashboards e UX
- consolidação de fluxos operacionais
- expansão da cobertura de testes

## Segurança

A estrutura do sistema foi pensada para seguir boas práticas, incluindo:

- PDO com prepared statements
- validação de dados de entrada
- separação de responsabilidades
- controle de acesso por contexto e permissões
- arquitetura preparada para crescimento sem conflitar plataforma e tenant

## Diferenciais

- ERP com foco em operação real
- arquitetura modular e evolutiva
- suporte a múltiplos clientes/tenants
- foco em gestão fiscal e transporte
- base apropriada para extensão e desenvolvimento profissional

## Contribuição

1. criar uma branch para a alteração
2. implementar a mudança com foco em clareza e arquitetura
3. manter a documentação atualizada
4. validar os testes relevantes
5. enviar a alteração por pull request

## Licença

Uso do projeto para estudo, evolução e desenvolvimento profissional conforme as regras do repositório e do autor.

---

Este README foi escrito para refletir o funcionamento real do sistema e seus módulos principais, mantendo foco em tecnologia, operação e arquitetura do produto.
