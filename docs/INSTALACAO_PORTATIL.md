# Instalação portátil do MiniERP

Este pacote contém somente o código-fonte versionado. Por segurança, ele não inclui
bancos de dados reais, backups, certificados A1, chaves privadas, cookies, sessões,
logs, arquivos `.env` nem segredos de API.

## Requisitos

- PHP 8.2 com DOM, JSON, LibXML, OpenSSL, SimpleXML, SOAP e Zlib;
- Composer;
- MariaDB/MySQL;
- Apache (XAMPP) ou outro servidor web compatível.

## Instalação em outra máquina

1. Extraia o ZIP dentro de `C:\xampp\htdocs\MiniRP`.
2. Abra um terminal em `C:\xampp\htdocs\MiniRP\mini-erp-web`.
3. Execute `composer install --no-dev --optimize-autoloader`.
4. Configure localmente as variáveis de ambiente e a conexão do banco usadas pelo
   `config.php`. Não grave senhas no Git.
5. Crie/restaure os bancos necessários e aplique os arquivos de `migrations/`.
6. Inicie Apache e MariaDB no XAMPP.
7. Acesse a aplicação pela URL configurada no Apache.

## Dados que precisam ser transferidos separadamente

Se a intenção for copiar também um ambiente operacional, transfira por um canal
privado e criptografado:

- dump do banco central e de cada tenant;
- certificado A1 (`.pfx`/`.p12`) e sua senha;
- chave mestra e segredos fiscais/CSRT;
- configurações locais não versionadas.

Nunca publique esses itens em uma release do GitHub. Depois da instalação, restaure-os
diretamente no servidor de destino e restrinja as permissões dos arquivos.

## Conferência rápida

- execute `composer install` sem erros;
- confirme a conexão com o banco;
- valide o login e o tenant ativo;
- teste emissão somente em homologação antes de habilitar produção;
- confira certificado, série, CSC/CSRT e dados do responsável técnico no destino.
