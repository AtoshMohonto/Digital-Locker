-- Digital Locker - Password Manager
-- MySQL schema and seed data
-- Import via phpMyAdmin or: mysql -u root < database.sql

CREATE DATABASE IF NOT EXISTS digital_locker
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE digital_locker;

-- ---------------------------------------------------------------------------
-- Roles
-- ---------------------------------------------------------------------------
DROP TABLE IF EXISTS project_categories;
DROP TABLE IF EXISTS project_types;
DROP TABLE IF EXISTS credential_types;
DROP TABLE IF EXISTS personal_notes;
DROP TABLE IF EXISTS project_comments;
DROP TABLE IF EXISTS project_tasks;
DROP TABLE IF EXISTS project_members;
DROP TABLE IF EXISTS role_permissions;
DROP TABLE IF EXISTS user_roles;
DROP TABLE IF EXISTS passwords;
DROP TABLE IF EXISTS projects;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS roles;
DROP TABLE IF EXISTS settings;

CREATE TABLE roles (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(80)  NOT NULL UNIQUE,
  description   VARCHAR(255) NOT NULL DEFAULT '',
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Permissions granted to each role (RBAC)
-- ---------------------------------------------------------------------------
CREATE TABLE role_permissions (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  role_id     INT UNSIGNED NOT NULL,
  permission  VARCHAR(60) NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_role_permission (role_id, permission),
  CONSTRAINT fk_rp_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Users
-- ---------------------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  username      VARCHAR(50)  NOT NULL UNIQUE,
  email         VARCHAR(120) NOT NULL UNIQUE,
  full_name     VARCHAR(120) NOT NULL DEFAULT '',
  password_hash VARCHAR(255) NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login    DATETIME     NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- User <-> Role (a user can hold several roles)
CREATE TABLE user_roles (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  role_id    INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_user_role (user_id, role_id),
  CONSTRAINT fk_ur_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ur_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Projects (organizational grouping)
-- ---------------------------------------------------------------------------
CREATE TABLE projects (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(100) NOT NULL UNIQUE,
  category    VARCHAR(60)  NOT NULL DEFAULT '',
  type        VARCHAR(60)  NOT NULL DEFAULT '',
  role        VARCHAR(80)  NOT NULL DEFAULT '',
  description VARCHAR(255) NOT NULL DEFAULT '',
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Project "Role": the web app's functional purpose (Data Collection,
-- E-commerce, Login System, ...), a flat catalog -- suggestions narrowed to
-- a given project's Category+Type combo are computed from actual projects
-- at read time (see projectPurposesByContext() in includes/functions.php),
-- not stored here.
CREATE TABLE project_purposes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(80) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Passwords (values stored encrypted via openssl)
-- ---------------------------------------------------------------------------
CREATE TABLE passwords (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title         VARCHAR(150) NOT NULL,
  category      VARCHAR(60)  NOT NULL DEFAULT '',
  username      VARCHAR(120) NOT NULL DEFAULT '',
  email         VARCHAR(120) NOT NULL DEFAULT '',
  phone         VARCHAR(40)  NOT NULL DEFAULT '',
  encrypted     TEXT         NOT NULL,
  url           VARCHAR(255) NOT NULL DEFAULT '',
  notes         TEXT,
  extra_info    TEXT,
  project_id    INT UNSIGNED NULL,
  assigned_to   INT UNSIGNED NULL,
  created_by    INT UNSIGNED NULL,
  created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_p_project  FOREIGN KEY (project_id)  REFERENCES projects(id) ON DELETE SET NULL,
  CONSTRAINT fk_p_assigned FOREIGN KEY (assigned_to) REFERENCES users(id)    ON DELETE SET NULL,
  CONSTRAINT fk_p_creator  FOREIGN KEY (created_by)  REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB;

-- Access level: many-to-many so a credential can be visible to several roles at once.
CREATE TABLE password_roles (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  password_id INT UNSIGNED NOT NULL,
  role_id     INT UNSIGNED NOT NULL,
  UNIQUE KEY uniq_password_role (password_id, role_id),
  CONSTRAINT fk_pr_password FOREIGN KEY (password_id) REFERENCES passwords(id) ON DELETE CASCADE,
  CONSTRAINT fk_pr_role     FOREIGN KEY (role_id)      REFERENCES roles(id)     ON DELETE CASCADE
) ENGINE=InnoDB;

-- Credential Assignments: several Managers and several Testers can be marked
-- against one credential at once (the Assignments page's "Assign Manager" /
-- "Assign Tester" pickers), independent of passwords.assigned_to.
CREATE TABLE password_assignees (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  password_id INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NOT NULL,
  kind        ENUM('manager','tester') NOT NULL,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_password_assignee (password_id, user_id, kind),
  CONSTRAINT fk_pa_password FOREIGN KEY (password_id) REFERENCES passwords(id) ON DELETE CASCADE,
  CONSTRAINT fk_pa_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Audit log (credential reveals and CRUD actions on vault entries)
-- ---------------------------------------------------------------------------
CREATE TABLE audit_log (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id        INT UNSIGNED NULL,
  action         VARCHAR(30) NOT NULL,
  password_id    INT UNSIGNED NULL,
  password_title VARCHAR(150) NOT NULL DEFAULT '',
  ip_address     VARCHAR(45) NOT NULL DEFAULT '',
  created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_al_user     FOREIGN KEY (user_id)     REFERENCES users(id)     ON DELETE SET NULL,
  CONSTRAINT fk_al_password FOREIGN KEY (password_id) REFERENCES passwords(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Personal Vault: strictly private per-user credentials (social, bank, etc.).
-- Never joined against roles/assigned_to -- access is enforced purely by user_id.
-- ---------------------------------------------------------------------------
CREATE TABLE personal_passwords (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  title       VARCHAR(150) NOT NULL,
  category    VARCHAR(60)  NOT NULL DEFAULT '',
  username    VARCHAR(120) NOT NULL DEFAULT '',
  encrypted   TEXT         NOT NULL,
  url         VARCHAR(255) NOT NULL DEFAULT '',
  notes       TEXT,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Settings (key/value)
-- ---------------------------------------------------------------------------
CREATE TABLE settings (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  setting_key VARCHAR(60) NOT NULL UNIQUE,
  setting_value TEXT NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Project workflow: team membership, tasks, and a discussion/review feed.
-- Manager assigns Testers to a project and gives them tasks; Testers post
-- reviews/notes in the project's discussion. Administrator oversees all of it.
-- ---------------------------------------------------------------------------
CREATE TABLE project_members (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  user_id      INT UNSIGNED NOT NULL,
  project_role ENUM('manager','tester') NOT NULL DEFAULT 'tester',
  added_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_project_user (project_id, user_id),
  CONSTRAINT fk_pm_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE project_tasks (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id   INT UNSIGNED NOT NULL,
  title        VARCHAR(150) NOT NULL,
  description  TEXT,
  assigned_to  INT UNSIGNED NULL,
  status       ENUM('open','in_progress','done') NOT NULL DEFAULT 'open',
  due_date     DATE NULL,
  created_by   INT UNSIGNED NULL,
  created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pt_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pt_assigned FOREIGN KEY (assigned_to) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_pt_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE project_comments (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  project_id      INT UNSIGNED NOT NULL,
  user_id         INT UNSIGNED NULL,
  author_name     VARCHAR(120) NOT NULL DEFAULT '',
  body            TEXT NOT NULL,
  attachment_path VARCHAR(255) NULL,
  created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_pc_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Personal to-do list & notes: strictly private per user (same isolation
-- model as Personal Vault), optionally tagged to a project.
-- ---------------------------------------------------------------------------
-- ---------------------------------------------------------------------------
-- Project categories: admin-editable (create/delete), used to populate the
-- category dropdown/filter on Projects instead of a fixed list.
-- ---------------------------------------------------------------------------
CREATE TABLE project_categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL UNIQUE,
  -- Either a typed emoji, or an uploaded image's relative path
  -- (e.g. "assets/uploads/categories/<name>.jpg").
  icon       VARCHAR(255) NOT NULL DEFAULT '📁',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- A second, independent classification for projects (e.g. Web App, Mobile
-- App, API/Service), alongside category -- same catalogue-table pattern.
CREATE TABLE project_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL UNIQUE,
  icon       VARCHAR(255) NOT NULL DEFAULT '🏷️',
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Credential "Type" (the "Role — System Name" prefix, e.g. Admin, Teacher):
-- same catalogue-table pattern, managed alongside project categories/types.
CREATE TABLE credential_types (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(60) NOT NULL UNIQUE,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE personal_notes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     INT UNSIGNED NOT NULL,
  project_id  INT UNSIGNED NULL,
  type        ENUM('todo','note') NOT NULL DEFAULT 'note',
  title       VARCHAR(200) NOT NULL,
  body        TEXT NULL,
  is_done     TINYINT(1) NOT NULL DEFAULT 0,
  created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_pn_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_pn_project FOREIGN KEY (project_id) REFERENCES projects(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------------
-- Seed: permissions catalogue used by the UI
-- ---------------------------------------------------------------------------
-- passwords.view     - see password entries / reveal secrets
-- passwords.manage   - create, edit and delete password entries
-- projects.manage    - create projects
-- roles.manage       - create/edit/delete roles and permissions
-- users.manage       - create/edit/disable users and assign roles
-- settings.manage    - change application settings
-- tasks.manage       - assign project team members and create/edit tasks
-- tasks.view         - see and act on tasks assigned to you

-- ---------------------------------------------------------------------------
-- Seed data
-- ---------------------------------------------------------------------------
INSERT INTO roles (id, name, description) VALUES
  (1, 'Administrator', 'Full access to everything in the vault.'),
  (2, 'Manager',      'Can manage passwords and projects, view users.'),
  (3, 'Tester',       'Assigned to specific projects to test and review; read-only on the vault.');

INSERT INTO role_permissions (role_id, permission) VALUES
  (1, 'passwords.view'),   (1, 'passwords.manage'),
  (1, 'projects.manage'),  (1, 'roles.manage'),
  (1, 'users.manage'),     (1, 'settings.manage'),
  (1, 'tasks.manage'),     (1, 'tasks.view'),
  (2, 'passwords.view'),   (2, 'passwords.manage'),
  (2, 'projects.manage'),
  (2, 'tasks.manage'),     (2, 'tasks.view'),
  (3, 'passwords.view'),   (3, 'tasks.view');

-- admin / admin123 ; manager / manager123 ; tester / viewer123
INSERT INTO users (id, username, email, full_name, password_hash, is_active) VALUES
  (1, 'admin',   'admin@example.com',    'System Administrator', '$2y$10$t2dDBml7f6/ui6w1tnMZ5.rk69acHx8i0ETZEoTSS7Fvu26JqT0q.', 1),
  (2, 'manager', 'manager@example.com',  'Vault Manager',        '$2y$10$nqxwa6XUZPTQZIb3xAATF.0r/t2fMqk1ux.IU10dpDakTd0yklCF6', 1),
  (3, 'tester',  'tester@example.com',   'QA Tester',            '$2y$10$ACGD3eAGzafGtjMwZeC8oOo2hwl4bleFOBj.NHTffcxY7F7E4QIk.', 1);

INSERT INTO user_roles (user_id, role_id) VALUES
  (1, 1),
  (2, 2),
  (3, 3);

INSERT INTO project_categories (name, icon) VALUES
  ('Work', '💼'), ('Freelancing', '🧑‍💻'), ('Client', '🤝'), ('Demo', '🧪'),
  ('Personal', '🏠'), ('Learning', '📚'), ('Other', '📁');

INSERT INTO project_types (name, icon) VALUES
  ('Web App', '🌐'), ('Mobile App', '📱'), ('Desktop App', '🖥️'),
  ('API/Service', '🔌'), ('Other', '🏷️');

INSERT INTO project_purposes (name) VALUES
  ('Data Collection'), ('E-commerce'), ('Login System'), ('Portfolio Site'),
  ('Blog/CMS'), ('Internal Tool'), ('API/Backend Service'), ('Other');

INSERT INTO projects (id, name, category, description) VALUES
  (1, 'General',        'Work', 'Shared and default passwords'),
  (2, 'Web Applications','Work', 'Credentials for internal web apps'),
  (3, 'Infrastructure', 'Work', 'Servers, databases and network gear');

-- NOTE: `encrypted` below is real AES-256-GCM ciphertext (base64 of iv+tag+ciphertext),
-- produced by includes/functions.php::encrypt_password() using the master_key that ships
-- in config/config.php. Decrypted values are: Mail Server = MailAdmin!2024Secure,
-- CRM Portal = CrmBot#2024Strong, WiFi Admin = WifiGuest$2024Pass.
-- If you change crypto.master_key in config.php, these seeded rows will no longer decrypt
-- (same as any other password already stored) -- re-run the equivalent of tools/reencrypt
-- or just edit/re-save the entries after changing the key.
INSERT INTO passwords (id, title, category, username, encrypted, url, notes, project_id, created_by) VALUES
  (1, 'Mail Server',    'Server',      'postmaster', 'l3uQgwmYE1eAxxAAnHENtDKxYjcK/lGPyhNdNbMfEqKBYiYXa/eGwuG/uAG4P1l7', 'https://mail.example.com', 'Primary MX', 3, 1),
  (2, 'CRM Portal',     'Application', 'sales_bot',  'Mas7G+Tn7pa8dpM7IS4Bn8zUKq+K5yETW7Z6a8uWtjpf/rTwsWQSRAiQYj+l', 'https://crm.example.com',  'Billing admin', 2, 1),
  (3, 'WiFi Admin',     'Router/Network', 'wifi',    '1kxSjG38iwf6wv7P0i6+dCzaVQ9YD7ZLc77ZvsdZ2RTM9WzQpSlOFwgAEpcfMg==', '',                          'Guest network', 1, 1);

-- Access level per credential (many-to-many): Mail Server & CRM Portal -> Manager+,
-- WiFi Admin -> Administrator only.
INSERT INTO password_roles (password_id, role_id) VALUES
  (1, 2),
  (2, 2),
  (3, 1);

INSERT INTO settings (setting_key, setting_value) VALUES
  ('password_policy', 'min_12_upper_lower_digit_special'),
  ('auto_lock_minutes', '5'),
  ('require_confirm_reveal', '1'),
  ('app_name', 'Digital Locker');

-- Auto-increment should continue past seeded ids
ALTER TABLE roles    AUTO_INCREMENT = 10;
ALTER TABLE users    AUTO_INCREMENT = 10;
ALTER TABLE projects AUTO_INCREMENT = 10;
ALTER TABLE passwords AUTO_INCREMENT = 10;
