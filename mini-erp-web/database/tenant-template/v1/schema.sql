CREATE TABLE IF NOT EXISTS clientes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    telefone VARCHAR(50) DEFAULT '',
    cpf_cnpj VARCHAR(20) DEFAULT '',
    inscricao_estadual VARCHAR(50) DEFAULT '',
    logradouro VARCHAR(150) DEFAULT '',
    numero VARCHAR(20) DEFAULT '',
    complemento VARCHAR(100) DEFAULT '',
    bairro VARCHAR(100) DEFAULT '',
    municipio VARCHAR(100) DEFAULT '',
    codigo_municipal VARCHAR(20) DEFAULT '',
    uf VARCHAR(2) DEFAULT '',
    cep VARCHAR(20) DEFAULT '',
    cidade VARCHAR(100) DEFAULT '',
    nome_fantasia VARCHAR(150) DEFAULT '',
    tipo_pessoa VARCHAR(100) DEFAULT 'cliente',
    pessoa_fisica VARCHAR(10) DEFAULT 'sim',
    aniversario DATE NULL,
    genero VARCHAR(30) DEFAULT '',
    data_cadastro DATE NULL,
    nome_contato VARCHAR(150) DEFAULT '',
    fone_principal VARCHAR(50) DEFAULT '',
    fone_2 VARCHAR(50) DEFAULT '',
    fone_3 VARCHAR(50) DEFAULT '',
    estado VARCHAR(100) DEFAULT '',
    ponto_referencia VARCHAR(150) DEFAULT '',
    codigo_ibge VARCHAR(7) DEFAULT '',
    suprama VARCHAR(50) DEFAULT '',
    im VARCHAR(50) DEFAULT '',
    vendedor VARCHAR(150) DEFAULT '',
    status_pagamento VARCHAR(50) DEFAULT '',
    pagamento VARCHAR(50) DEFAULT '',
    anvisa_data_venc DATE NULL,
    anvisa_codigo VARCHAR(50) DEFAULT '',
    comissao_percentual VARCHAR(20) DEFAULT '',
    comissao_volume VARCHAR(20) DEFAULT '',
    forma_pagamento VARCHAR(50) DEFAULT '',
    limite_credito DECIMAL(10,2) DEFAULT 0.00,
    desconto DECIMAL(10,2) DEFAULT 0.00,
    funeral VARCHAR(20) DEFAULT '',
    transportadora VARCHAR(150) DEFAULT '',
    placa VARCHAR(20) DEFAULT '',
    placa_uf VARCHAR(10) DEFAULT '',
    antt VARCHAR(50) DEFAULT '',
    frete DECIMAL(10,2) DEFAULT 0.00,
    valor_frete DECIMAL(10,2) DEFAULT 0.00,
    data LONGTEXT NULL,
    person_type VARCHAR(7) NOT NULL DEFAULT 'PF',
    foreign_id VARCHAR(50) NOT NULL DEFAULT '',
    state_registration_indicator CHAR(1) NOT NULL DEFAULT '9',
    rg VARCHAR(30) NOT NULL DEFAULT '',
    country_code VARCHAR(4) NOT NULL DEFAULT '1058',
    country_name VARCHAR(60) NOT NULL DEFAULT 'BRASIL',
    observations TEXT NULL,
    role_customer TINYINT(1) NOT NULL DEFAULT 1,
    role_supplier TINYINT(1) NOT NULL DEFAULT 0,
    role_seller TINYINT(1) NOT NULL DEFAULT 0,
    role_carrier TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(20) DEFAULT 'ativo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS produtos (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    codigo VARCHAR(50) NOT NULL UNIQUE,
    ncm VARCHAR(20) DEFAULT '',
    cest VARCHAR(20) DEFAULT '',
    merchandise_origin CHAR(1) NOT NULL DEFAULT '',
    extipi VARCHAR(3) NOT NULL DEFAULT '',
    tax_benefit_code VARCHAR(20) NOT NULL DEFAULT '',
    fci_number CHAR(36) NOT NULL DEFAULT '',
    unidade VARCHAR(10) DEFAULT 'UN',
    taxable_unit VARCHAR(6) NOT NULL DEFAULT 'UN',
    conversion_factor DECIMAL(18,6) NOT NULL DEFAULT 1,
    gtin VARCHAR(50) DEFAULT '',
    gtin_tributable VARCHAR(14) NOT NULL DEFAULT 'SEM GTIN',
    cfop_padrao VARCHAR(20) DEFAULT '',
    categoria VARCHAR(80) DEFAULT '',
    cost_price DECIMAL(18,4) NOT NULL DEFAULT 0,
    preco DECIMAL(10,2) NOT NULL DEFAULT 0,
    estoque_atual INT NOT NULL DEFAULT 0,
    minimum_stock DECIMAL(18,4) NOT NULL DEFAULT 0,
    status VARCHAR(20) DEFAULT 'ativo',
    company_id INT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS vendas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    cliente_id INT NOT NULL,
    data_venda DATE NOT NULL,
    empresa_cnpj VARCHAR(20) DEFAULT NULL,
    total DECIMAL(10,2) NOT NULL DEFAULT 0,
    status VARCHAR(20) DEFAULT 'finalizada',
    CONSTRAINT fk_vendas_clientes FOREIGN KEY (cliente_id) REFERENCES clientes(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS itens_venda (
    id INT AUTO_INCREMENT PRIMARY KEY,
    venda_id INT NOT NULL,
    produto_id INT NOT NULL,
    quantidade INT NOT NULL,
    preco_unitario DECIMAL(10,2) NOT NULL,
    subtotal DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_itens_venda FOREIGN KEY (venda_id) REFERENCES vendas(id),
    CONSTRAINT fk_itens_produtos FOREIGN KEY (produto_id) REFERENCES produtos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS product_taxes (
    product_id INT PRIMARY KEY,
    ipi VARCHAR(50) DEFAULT '',
    ipi_output_cst VARCHAR(2) NULL,
    ipi_output_rate DECIMAL(15,6) NULL,
    ipi_input_cst VARCHAR(2) NULL,
    icms VARCHAR(50) DEFAULT '',
    icms_cst VARCHAR(3) NULL,
    icms_csosn VARCHAR(3) NULL,
    icms_consumer_cst VARCHAR(3) NULL,
    icms_rate DECIMAL(15,6) NULL,
    pis VARCHAR(50) DEFAULT '',
    pis_output_cst VARCHAR(2) NULL,
    pis_output_rate DECIMAL(15,6) NULL,
    pis_input_cst VARCHAR(2) NULL,
    pis_input_rate DECIMAL(15,6) NULL,
    cofins VARCHAR(50) DEFAULT '',
    cofins_output_cst VARCHAR(2) NULL,
    cofins_output_rate DECIMAL(15,6) NULL,
    cofins_input_cst VARCHAR(2) NULL,
    cofins_input_rate DECIMAL(15,6) NULL,
    source_document VARCHAR(160) NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_taxes_produto FOREIGN KEY (product_id) REFERENCES produtos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cfops (
    id INT AUTO_INCREMENT PRIMARY KEY,
    codigo VARCHAR(10) NOT NULL UNIQUE,
    descricao VARCHAR(255) NOT NULL,
    natureza VARCHAR(80) DEFAULT '',
    aplicacao VARCHAR(80) DEFAULT '',
    status VARCHAR(20) DEFAULT 'ativo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS fornecedores (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150) DEFAULT '',
    cpf_cnpj VARCHAR(20) DEFAULT '',
    inscricao_estadual VARCHAR(50) DEFAULT '',
    email VARCHAR(150) DEFAULT '',
    telefone VARCHAR(50) DEFAULT '',
    cep VARCHAR(20) DEFAULT '',
    logradouro VARCHAR(150) DEFAULT '',
    numero VARCHAR(20) DEFAULT '',
    complemento VARCHAR(100) DEFAULT '',
    bairro VARCHAR(100) DEFAULT '',
    municipio VARCHAR(100) DEFAULT '',
    uf VARCHAR(2) DEFAULT '',
    cidade VARCHAR(100) DEFAULT '',
    data LONGTEXT NULL,
    status VARCHAR(20) DEFAULT 'ativo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS motoristas (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    cpf VARCHAR(20) DEFAULT '',
    cnh VARCHAR(20) DEFAULT '',
    categoria_cnh VARCHAR(10) DEFAULT '',
    vencimento_cnh DATE DEFAULT NULL,
    telefone VARCHAR(50) DEFAULT '',
    status VARCHAR(20) DEFAULT 'ativo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transportadoras (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(150) NOT NULL,
    nome_fantasia VARCHAR(150) DEFAULT '',
    cpf_cnpj VARCHAR(20) DEFAULT '',
    inscricao_estadual VARCHAR(50) DEFAULT '',
    email VARCHAR(150) DEFAULT '',
    telefone VARCHAR(50) DEFAULT '',
    cep VARCHAR(20) DEFAULT '',
    logradouro VARCHAR(150) DEFAULT '',
    numero VARCHAR(20) DEFAULT '',
    complemento VARCHAR(100) DEFAULT '',
    bairro VARCHAR(100) DEFAULT '',
    municipio VARCHAR(100) DEFAULT '',
    uf VARCHAR(2) DEFAULT '',
    cidade VARCHAR(100) DEFAULT '',
    status VARCHAR(20) DEFAULT 'ativo'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS establishments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id INT UNSIGNED NOT NULL,
    tax_id VARCHAR(14) NOT NULL,
    legal_name VARCHAR(150) NOT NULL,
    trade_name VARCHAR(150) NOT NULL DEFAULT '',
    state_registration VARCHAR(30) NOT NULL,
    st_registration VARCHAR(30) NOT NULL DEFAULT '',
    municipal_registration VARCHAR(30) NOT NULL DEFAULT '',
    cnae VARCHAR(7) NOT NULL DEFAULT '',
    tax_regime_code CHAR(1) NOT NULL,
    street VARCHAR(150) NOT NULL,
    number VARCHAR(20) NOT NULL,
    complement VARCHAR(100) NOT NULL DEFAULT '',
    district VARCHAR(100) NOT NULL,
    city_ibge_code CHAR(7) NOT NULL,
    city_name VARCHAR(100) NOT NULL,
    state CHAR(2) NOT NULL,
    postal_code CHAR(8) NOT NULL,
    country_code VARCHAR(4) NOT NULL DEFAULT '1058',
    country_name VARCHAR(60) NOT NULL DEFAULT 'BRASIL',
    phone VARCHAR(30) NOT NULL DEFAULT '',
    email VARCHAR(150) NOT NULL DEFAULT '',
    establishment_type VARCHAR(20) NOT NULL DEFAULT 'MATRIZ',
    is_primary TINYINT(1) NOT NULL DEFAULT 1,
    status VARCHAR(10) NOT NULL DEFAULT 'ativo',
    fiscal_readiness VARCHAR(20) NOT NULL DEFAULT 'INCOMPLETE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_establishments_tenant_tax_id (tenant_id, tax_id),
    KEY idx_establishments_primary (tenant_id, is_primary)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_orders (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,operation_type VARCHAR(5) NOT NULL,establishment_id INT UNSIGNED NULL,person_id INT NULL,internal_code VARCHAR(50) NOT NULL DEFAULT '',operation_date DATE NOT NULL,commercial_status VARCHAR(12) NOT NULL DEFAULT 'SAVED',fiscal_status VARCHAR(20) NOT NULL DEFAULT 'NOT_CREATED',operation_nature VARCHAR(120) NOT NULL DEFAULT '',fiscal_model CHAR(2) NOT NULL DEFAULT '55',purpose VARCHAR(20) NOT NULL DEFAULT 'NORMAL',final_consumer TINYINT(1) NOT NULL DEFAULT 0,presence_indicator VARCHAR(2) NOT NULL DEFAULT '1',payment_condition VARCHAR(30) NOT NULL DEFAULT '',payment_method VARCHAR(30) NOT NULL DEFAULT '',first_due_date DATE NULL,notes TEXT NULL,seller_id INT NULL,carrier_id INT NULL,driver_id INT NULL,freight_mode VARCHAR(2) NOT NULL DEFAULT '9',discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,freight_amount DECIMAL(18,2) NOT NULL DEFAULT 0,insurance_amount DECIMAL(18,2) NOT NULL DEFAULT 0,other_amount DECIMAL(18,2) NOT NULL DEFAULT 0,products_total DECIMAL(18,2) NOT NULL DEFAULT 0,grand_total DECIMAL(18,2) NOT NULL DEFAULT 0,created_by INT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY idx_fiscal_orders_tenant(tenant_id,operation_type,commercial_status)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fiscal_order_transport_details (order_id BIGINT UNSIGNED NOT NULL,tenant_id INT UNSIGNED NOT NULL,vehicle_plate VARCHAR(10) NULL,vehicle_state CHAR(2) NULL,vehicle_rntc VARCHAR(20) NULL,volume_quantity INT UNSIGNED NULL,volume_species VARCHAR(60) NULL,volume_brand VARCHAR(60) NULL,volume_numbering VARCHAR(60) NULL,gross_weight DECIMAL(15,3) NULL,net_weight DECIMAL(15,3) NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,PRIMARY KEY(order_id),KEY idx_order_transport_tenant(tenant_id,order_id),CONSTRAINT fk_order_transport_order FOREIGN KEY(order_id) REFERENCES fiscal_orders(id) ON DELETE CASCADE) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fiscal_order_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,order_id BIGINT UNSIGNED NOT NULL,product_id INT NOT NULL,quantity DECIMAL(18,4) NOT NULL,unit_price DECIMAL(18,4) NOT NULL,discount_amount DECIMAL(18,2) NOT NULL DEFAULT 0,freight_amount DECIMAL(18,2) NOT NULL DEFAULT 0,insurance_amount DECIMAL(18,2) NOT NULL DEFAULT 0,other_amount DECIMAL(18,2) NOT NULL DEFAULT 0,gross_total DECIMAL(18,2) NOT NULL,net_total DECIMAL(18,2) NOT NULL,CONSTRAINT fk_fiscal_order_item_order FOREIGN KEY(order_id) REFERENCES fiscal_orders(id),CONSTRAINT fk_fiscal_order_item_product FOREIGN KEY(product_id) REFERENCES produtos(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fiscal_mirrors (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,source_order_id BIGINT UNSIGNED NOT NULL,snapshot_version INT UNSIGNED NOT NULL,operation_snapshot_json JSON NOT NULL,pending_json JSON NOT NULL,created_by INT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_fiscal_mirror_version(tenant_id,source_order_id,snapshot_version),CONSTRAINT fk_fiscal_mirror_order FOREIGN KEY(source_order_id) REFERENCES fiscal_orders(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fiscal_documents (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,source_order_id BIGINT UNSIGNED NOT NULL,document_version INT UNSIGNED NOT NULL DEFAULT 1,idempotency_key CHAR(64) NOT NULL,status VARCHAR(20) NOT NULL,pending_json JSON NOT NULL,issuer_snapshot_json JSON NOT NULL,recipient_snapshot_json JSON NOT NULL,payment_snapshot_json JSON NOT NULL,transport_snapshot_json JSON NOT NULL,totals_json JSON NOT NULL,created_by INT NOT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_fiscal_document_idempotency(tenant_id,idempotency_key),UNIQUE KEY uq_fiscal_document_version(tenant_id,source_order_id,document_version),CONSTRAINT fk_fiscal_document_order FOREIGN KEY(source_order_id) REFERENCES fiscal_orders(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS fiscal_document_items (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,fiscal_document_id BIGINT UNSIGNED NOT NULL,source_order_item_id BIGINT UNSIGNED NOT NULL,product_id INT NOT NULL,product_snapshot_json JSON NOT NULL,quantity_commercial DECIMAL(18,4) NOT NULL,quantity_taxable DECIMAL(18,4) NOT NULL,unit_value_commercial DECIMAL(18,4) NOT NULL,unit_value_taxable DECIMAL(18,4) NOT NULL,gross_total DECIMAL(18,2) NOT NULL,discount_amount DECIMAL(18,2) NOT NULL,freight_amount DECIMAL(18,2) NOT NULL,insurance_amount DECIMAL(18,2) NOT NULL,other_amount DECIMAL(18,2) NOT NULL,net_total DECIMAL(18,2) NOT NULL,included_in_total TINYINT(1) NOT NULL DEFAULT 1,fiscal_status VARCHAR(20) NOT NULL,tax_context_json JSON NOT NULL,tax_resolution_json JSON NULL,CONSTRAINT fk_fiscal_document_item_document FOREIGN KEY(fiscal_document_id) REFERENCES fiscal_documents(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS tax_rule_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id INT UNSIGNED NOT NULL,
    rule_code VARCHAR(80) NOT NULL, rule_version INT UNSIGNED NOT NULL, priority INT NOT NULL DEFAULT 0,
    valid_from DATE NOT NULL, valid_to DATE NULL, source_document VARCHAR(160) NOT NULL,
    source_version VARCHAR(40) NOT NULL, source_date DATE NOT NULL, conditions_json JSON NOT NULL,
    cfop CHAR(4) NOT NULL, icms_json JSON NOT NULL, ipi_json JSON NOT NULL, pis_json JSON NOT NULL,
    cofins_json JSON NOT NULL, ibs_cbs_json JSON NOT NULL, selective_tax_json JSON NOT NULL,
    status VARCHAR(12) NOT NULL DEFAULT 'ACTIVE', fixture_kind VARCHAR(12) NOT NULL DEFAULT 'PRODUCTION',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_tax_rule_version (tenant_id,rule_code,rule_version),
    KEY idx_tax_rule_effective (tenant_id,status,valid_from,valid_to,priority)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_reference_versions (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference_type VARCHAR(50) NOT NULL,
    source_document VARCHAR(160) NOT NULL, source_version VARCHAR(40) NOT NULL, published_at DATE NOT NULL,
    checksum_sha256 CHAR(64) NOT NULL, effective_from DATE NULL, effective_to DATE NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fiscal_reference_version (reference_type,source_version,checksum_sha256)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_classifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, reference_version_id BIGINT UNSIGNED NOT NULL,
    classification_type VARCHAR(30) NOT NULL, code VARCHAR(30) NOT NULL, description VARCHAR(500) NOT NULL,
    metadata_json JSON NOT NULL,
    UNIQUE KEY uq_fiscal_classification (reference_version_id,classification_type,code),
    CONSTRAINT fk_fiscal_classification_version FOREIGN KEY (reference_version_id) REFERENCES fiscal_reference_versions(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_series (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL,
    establishment_id BIGINT UNSIGNED NOT NULL, model CHAR(2) NOT NULL, series INT UNSIGNED NOT NULL,
    next_number BIGINT UNSIGNED NOT NULL DEFAULT 1, environment TINYINT UNSIGNED NOT NULL DEFAULT 2,
    emission_type TINYINT UNSIGNED NOT NULL DEFAULT 1, process_version VARCHAR(40) NOT NULL,
    active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_fiscal_series (tenant_id,establishment_id,model,series)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_number_reservations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    establishment_id BIGINT UNSIGNED NOT NULL,
    fiscal_document_id BIGINT UNSIGNED NOT NULL,
    fiscal_document_version INT UNSIGNED NOT NULL DEFAULT 1,
    fiscal_series_id BIGINT UNSIGNED NULL,
    model CHAR(2) NOT NULL,
    series INT UNSIGNED NOT NULL,
    number BIGINT UNSIGNED NOT NULL,
    fiscal_number BIGINT UNSIGNED NULL,
    environment TINYINT UNSIGNED NOT NULL DEFAULT 2,
    cnf VARCHAR(32) NULL,
    numeric_code CHAR(8) NULL,
    access_key VARCHAR(64) NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'RESERVED',
    idempotency_key VARCHAR(128) NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_reservation_document_version (tenant_id,fiscal_document_id,fiscal_document_version),
    UNIQUE KEY uq_reservation_number (tenant_id,establishment_id,model,series,number),
    UNIQUE KEY uq_reservation_access_key (tenant_id,access_key),
    KEY idx_reservation_scope (tenant_id,establishment_id,model,series,status),
    KEY idx_reservation_document (tenant_id,fiscal_document_id),
    KEY idx_reservation_series (fiscal_series_id),
    KEY idx_reservation_idempotency (tenant_id,idempotency_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_artifacts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tenant_id BIGINT UNSIGNED NOT NULL,
    establishment_id BIGINT UNSIGNED NOT NULL,
    fiscal_document_id BIGINT UNSIGNED NOT NULL,
    fiscal_document_version INT UNSIGNED NOT NULL DEFAULT 1,
    certificate_id BIGINT UNSIGNED NULL,
    number_reservation_id BIGINT UNSIGNED NULL,
    model CHAR(2) NOT NULL,
    environment TINYINT UNSIGNED NOT NULL DEFAULT 2,
    series INT UNSIGNED NOT NULL,
    number BIGINT UNSIGNED NOT NULL,
    access_key VARCHAR(64) NOT NULL,
    artifact_type VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'XSD_VALID_OFFLINE',
    schema_package VARCHAR(80) NOT NULL DEFAULT 'nfe',
    schema_version VARCHAR(32) NOT NULL DEFAULT '010e-v1.02',
    schema_checksum CHAR(64) NOT NULL DEFAULT '',
    storage_reference VARCHAR(500) NOT NULL,
    sha256 CHAR(64) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    size BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_artifact_document_version (tenant_id,fiscal_document_id,fiscal_document_version,artifact_type),
    KEY idx_artifact_document (tenant_id,fiscal_document_id),
    KEY idx_artifact_access_key (tenant_id,access_key),
    KEY idx_artifact_reservation (number_reservation_id),
    KEY idx_artifact_certificate (certificate_id),
    KEY idx_artifact_status (tenant_id,status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fiscal_certificates (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,establishment_id BIGINT UNSIGNED NOT NULL,storage_reference VARCHAR(500) NOT NULL,file_name VARCHAR(255) NOT NULL,sha256 CHAR(64) NOT NULL,fingerprint_sha256 CHAR(64) NOT NULL,subject TEXT NOT NULL,issuer TEXT NOT NULL,serial_number VARCHAR(255) NOT NULL,tax_id VARCHAR(32) NULL,valid_from DATETIME NOT NULL,valid_until DATETIME NOT NULL,status VARCHAR(32) NOT NULL,secret_reference VARCHAR(500) NOT NULL,uploaded_by BIGINT UNSIGNED NULL,deactivated_by BIGINT UNSIGNED NULL,deactivated_at DATETIME NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY ix_active_certificate(tenant_id,establishment_id,active),KEY ix_certificate_scope(tenant_id,establishment_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fiscal_certificate_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,establishment_id BIGINT UNSIGNED NOT NULL,certificate_id BIGINT UNSIGNED NOT NULL,action VARCHAR(32) NOT NULL,actor_id BIGINT UNSIGNED NOT NULL,details_json LONGTEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ix_certificate_audit_scope(tenant_id,establishment_id,certificate_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fiscal_series_audit (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,establishment_id BIGINT UNSIGNED NOT NULL,fiscal_series_id BIGINT UNSIGNED NOT NULL,action VARCHAR(32) NOT NULL DEFAULT 'UPDATE',model CHAR(2) NOT NULL,series INT UNSIGNED NOT NULL,old_next_number BIGINT UNSIGNED NULL,new_next_number BIGINT UNSIGNED NOT NULL,changed_by BIGINT UNSIGNED NOT NULL,reason VARCHAR(500) NOT NULL,before_json LONGTEXT NULL,after_json LONGTEXT NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ix_series_audit_scope(tenant_id,establishment_id,model,series)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS tenant_schema_migrations (
 migration_id VARCHAR(190) NOT NULL PRIMARY KEY,
 checksum CHAR(64) NOT NULL,
 applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 execution_status VARCHAR(32) NOT NULL,
 duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
 operator_source VARCHAR(190) NOT NULL,
 KEY ix_tenant_schema_migrations_status (execution_status, applied_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- FISCAL-CONFIG-01: configuracoes fiscais da empresa, aditiva, vazia e sem defaults tributarios.
CREATE TABLE IF NOT EXISTS establishment_fiscal_settings (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL,
 environment TINYINT UNSIGNED NOT NULL DEFAULT 2, primary_model CHAR(2) NOT NULL DEFAULT '55',
 deduct_icms_from_pis_cofins_base TINYINT(1) NOT NULL DEFAULT 0, default_cst_csosn VARCHAR(3) NULL, final_consumer_cst_csosn VARCHAR(3) NULL,
 default_cbenef VARCHAR(20) NULL, final_consumer_cbenef VARCHAR(20) NULL, funrural_rate DECIMAL(15,6) NULL, simple_credit_rate DECIMAL(15,6) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_fiscal_settings_scope(tenant_id,establishment_id), KEY ix_est_fiscal_settings_tenant(tenant_id,establishment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_cfop_defaults (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL,
 operation_context VARCHAR(32) NOT NULL, cfop CHAR(4) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_cfop_context(tenant_id,establishment_id,operation_context)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_csc_credentials (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL, environment TINYINT UNSIGNED NOT NULL,
 id_csc VARCHAR(20) NOT NULL, secret_reference VARCHAR(500) NOT NULL, secret_suffix CHAR(4) NOT NULL, active TINYINT(1) NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_csc_environment(tenant_id,establishment_id,environment)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_icms_defaults (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL, uf CHAR(2) NOT NULL,
 juridica_rate DECIMAL(15,6) NULL, final_consumer_rate DECIMAL(15,6) NULL, reduction_rate DECIMAL(15,6) NULL, deferral_rate DECIMAL(15,6) NULL,
 mva_rate DECIMAL(15,6) NULL, simple_mva_rate DECIMAL(15,6) NULL, st_rate DECIMAL(15,6) NULL, st_reduction_rate DECIMAL(15,6) NULL,
 internal_rate DECIMAL(15,6) NULL, fcp_rate DECIMAL(15,6) NULL, cst_csosn VARCHAR(3) NULL, valid_from DATE NOT NULL, valid_to DATE NULL,
 active TINYINT(1) NOT NULL DEFAULT 1, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_icms_version(tenant_id,establishment_id,uf,valid_from), KEY ix_est_icms_effective(tenant_id,establishment_id,uf,active,valid_from,valid_to)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_legacy_tax_defaults (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL,
 pis_output_cst CHAR(2) NULL, pis_output_rate DECIMAL(15,6) NULL, pis_input_cst CHAR(2) NULL, pis_input_rate DECIMAL(15,6) NULL,
 cofins_output_cst CHAR(2) NULL, cofins_output_rate DECIMAL(15,6) NULL, cofins_input_cst CHAR(2) NULL, cofins_input_rate DECIMAL(15,6) NULL,
 ipi_output_cst CHAR(2) NULL, ipi_output_rate DECIMAL(15,6) NULL, ipi_input_cst CHAR(2) NULL, ipi_input_rate DECIMAL(15,6) NULL, ipi_cenq VARCHAR(3) NULL,
 ipi_applicability VARCHAR(20) NOT NULL DEFAULT 'PENDING', created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_legacy_tax_scope(tenant_id,establishment_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_rtc_defaults (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL, document_scope VARCHAR(3) NOT NULL,
 ibs_cbs_cst VARCHAR(3) NULL, cclass_trib VARCHAR(30) NULL, ibs_uf_rate DECIMAL(15,6) NULL, ibs_municipal_rate DECIMAL(15,6) NULL, cbs_rate DECIMAL(15,6) NULL,
 ibs_reduction_rate DECIMAL(15,6) NULL, cbs_reduction_rate DECIMAL(15,6) NULL, ibs_deferral_rate DECIMAL(15,6) NULL, cbs_deferral_rate DECIMAL(15,6) NULL,
 is_enabled TINYINT(1) NOT NULL DEFAULT 0, is_cst VARCHAR(3) NULL, is_classification VARCHAR(30) NULL, is_rate DECIMAL(15,6) NULL,
 is_type VARCHAR(16) NOT NULL DEFAULT 'NONE', is_unit VARCHAR(10) NULL, is_specific_value DECIMAL(18,6) NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_est_rtc_scope(tenant_id,establishment_id,document_scope)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS establishment_fiscal_settings_audit (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, tenant_id BIGINT UNSIGNED NOT NULL, establishment_id BIGINT UNSIGNED NOT NULL,
 setting_group VARCHAR(40) NOT NULL, actor_id BIGINT UNSIGNED NOT NULL, before_json LONGTEXT NULL, after_json LONGTEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_est_fiscal_audit(tenant_id,establishment_id,setting_group,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS fiscal_document_events (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id BIGINT UNSIGNED NOT NULL,fiscal_document_id BIGINT UNSIGNED NOT NULL,event_type VARCHAR(48) NOT NULL,stage VARCHAR(32) NOT NULL,status VARCHAR(32) NOT NULL,code VARCHAR(80) NULL,message VARCHAR(1000) NULL,metadata_json LONGTEXT NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ix_fiscal_event_document(tenant_id,fiscal_document_id,id),KEY ix_fiscal_event_status(tenant_id,status,created_at)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS stock_control_by_lot TINYINT(1) NOT NULL DEFAULT 0 AFTER minimum_stock;
ALTER TABLE produtos ADD COLUMN IF NOT EXISTS created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP;
ALTER TABLE fiscal_order_items ADD COLUMN IF NOT EXISTS stock_lot_id BIGINT UNSIGNED NULL AFTER product_id;
CREATE TABLE IF NOT EXISTS stock_locations (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,name VARCHAR(120) NOT NULL,code VARCHAR(40) NOT NULL,active TINYINT(1) NOT NULL DEFAULT 1,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,UNIQUE KEY uq_stock_location(tenant_id,code),KEY ix_stock_location_tenant(tenant_id,active)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS stock_lots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,product_id INT NOT NULL,location_id BIGINT UNSIGNED NOT NULL,lot_code VARCHAR(100) NOT NULL,manufactured_at DATE NULL,expires_at DATE NULL,quantity_available DECIMAL(18,4) NOT NULL DEFAULT 0,blocked_sale TINYINT(1) NOT NULL DEFAULT 0,block_reason VARCHAR(500) NULL,status VARCHAR(24) NOT NULL DEFAULT 'ACTIVE',created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_stock_lot_location(tenant_id,product_id,location_id,lot_code),KEY ix_stock_lot_product(tenant_id,product_id,status),KEY ix_stock_lot_expiry(tenant_id,expires_at),CONSTRAINT fk_stock_lot_product FOREIGN KEY(product_id) REFERENCES produtos(id),CONSTRAINT fk_stock_lot_location FOREIGN KEY(location_id) REFERENCES stock_locations(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS stock_movements (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,product_id INT NOT NULL,source_lot_id BIGINT UNSIGNED NULL,destination_lot_id BIGINT UNSIGNED NULL,movement_type VARCHAR(24) NOT NULL,quantity DECIMAL(18,4) NOT NULL,balance_before DECIMAL(18,4) NULL,balance_after DECIMAL(18,4) NULL,reference_type VARCHAR(40) NULL,reference_id BIGINT UNSIGNED NULL,reason VARCHAR(500) NOT NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ix_stock_movement_product(tenant_id,product_id,created_at),KEY ix_stock_movement_reference(tenant_id,reference_type,reference_id),CONSTRAINT fk_stock_movement_product FOREIGN KEY(product_id) REFERENCES produtos(id),CONSTRAINT fk_stock_movement_source FOREIGN KEY(source_lot_id) REFERENCES stock_lots(id),CONSTRAINT fk_stock_movement_destination FOREIGN KEY(destination_lot_id) REFERENCES stock_lots(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS stock_pending_lots (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,location_id BIGINT UNSIGNED NOT NULL,lot_code VARCHAR(100) NOT NULL,quantity_received DECIMAL(18,4) NOT NULL DEFAULT 0,manufactured_at DATE NULL,expires_at DATE NULL,source_document VARCHAR(120) NULL,notes VARCHAR(500) NULL,status VARCHAR(24) NOT NULL DEFAULT 'PENDING',linked_product_id INT NULL,linked_lot_id BIGINT UNSIGNED NULL,linked_at DATETIME NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,KEY ix_pending_lot_tenant_status(tenant_id,status,created_at),KEY ix_pending_lot_code(tenant_id,lot_code),CONSTRAINT fk_pending_lot_location FOREIGN KEY(location_id) REFERENCES stock_locations(id),CONSTRAINT fk_pending_lot_product FOREIGN KEY(linked_product_id) REFERENCES produtos(id),CONSTRAINT fk_pending_lot_stock_lot FOREIGN KEY(linked_lot_id) REFERENCES stock_lots(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS financial_accounts (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,account_type VARCHAR(10) NOT NULL,source_type VARCHAR(30) NOT NULL DEFAULT 'MANUAL',source_id BIGINT UNSIGNED NULL,person_id INT NULL,description VARCHAR(255) NOT NULL,document_number VARCHAR(80) NULL,issue_date DATE NOT NULL,due_date DATE NOT NULL,original_amount DECIMAL(18,2) NOT NULL,paid_amount DECIMAL(18,2) NOT NULL DEFAULT 0,status VARCHAR(20) NOT NULL DEFAULT 'OPEN',category VARCHAR(80) NULL,payment_method VARCHAR(40) NULL,notes VARCHAR(500) NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,UNIQUE KEY uq_financial_source(tenant_id,account_type,source_type,source_id),KEY ix_financial_due(tenant_id,account_type,status,due_date),KEY ix_financial_person(tenant_id,person_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS financial_payments (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,tenant_id INT UNSIGNED NOT NULL,account_id BIGINT UNSIGNED NOT NULL,amount DECIMAL(18,2) NOT NULL,paid_at DATETIME NOT NULL,payment_method VARCHAR(40) NULL,notes VARCHAR(500) NULL,created_by BIGINT UNSIGNED NULL,created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,KEY ix_financial_payment_account(tenant_id,account_id,paid_at),CONSTRAINT fk_financial_payment_account FOREIGN KEY(account_id) REFERENCES financial_accounts(id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- CT-e e MDF-e rodoviarios: rascunhos e validacao local isolados por empresa.
CREATE TABLE IF NOT EXISTS transport_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 model CHAR(2) NOT NULL,
 title VARCHAR(160) NOT NULL,
 status VARCHAR(24) NOT NULL DEFAULT 'DRAFT',
 payload_json LONGTEXT NOT NULL,
 snapshot_json LONGTEXT NOT NULL,
 validation_json LONGTEXT NULL,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_by BIGINT UNSIGNED NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 KEY ix_transport_scope (tenant_id,model,updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS transport_vehicles (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 plate VARCHAR(7) NOT NULL,
 state CHAR(2) NOT NULL,
 renavam VARCHAR(11) NOT NULL DEFAULT '',
 tare_kg INT UNSIGNED NOT NULL DEFAULT 0,
 capacity_kg INT UNSIGNED NOT NULL DEFAULT 0,
 wheel_type CHAR(2) NOT NULL DEFAULT '02',
 body_type CHAR(2) NOT NULL DEFAULT '00',
 owner_tax_id VARCHAR(14) NOT NULL DEFAULT '',
 owner_name VARCHAR(150) NOT NULL DEFAULT '',
 owner_rntrc VARCHAR(8) NOT NULL DEFAULT '',
 ownership CHAR(1) NOT NULL DEFAULT '0',
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY uq_transport_plate (tenant_id,plate)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS transport_settings (
 tenant_id INT UNSIGNED PRIMARY KEY,
 payload_json LONGTEXT NOT NULL,
 updated_by BIGINT UNSIGNED NOT NULL,
 updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE IF NOT EXISTS transport_document_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 tenant_id INT UNSIGNED NOT NULL,
 document_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 action VARCHAR(30) NOT NULL,
 actor_id BIGINT UNSIGNED NOT NULL,
 details_json LONGTEXT NOT NULL,
 created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 KEY ix_transport_events (tenant_id,document_id,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
