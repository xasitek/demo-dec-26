<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Shared : colonne users.modules (rattachement d'un utilisateur a des modules).
 *
 * Determine l'acces aux modules (sauf admin) et le perimetre des notifications
 * recues. Pre-rempli selon les acces actuels (roles) pour ne casser l'acces
 * d'aucun utilisateur existant :
 *   - Garanties     : ROLE_AUDITEUR (+ MANAGER/ADMIN par hierarchie)
 *   - Creances      : ROLE_COMPTABLE
 *   - Recouvrement  : ROLE_COMPTABLE ou ROLE_MANAGER
 *   - Bonus eco     : ROLE_MANAGER, ROLE_AUDITEUR ou ROLE_COMPTABLE
 *
 * jsonb_exists_any (et non l'operateur ?| ) : le caractere '?' est interprete
 * comme un placeholder par DBAL.
 */
final class Version20260804120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Shared : colonne users.modules (rattachement aux modules) + pre-remplissage selon les roles';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE shared.users ADD COLUMN modules JSON NOT NULL DEFAULT '[]'");

        $this->addSql(<<<'SQL'
            UPDATE shared.users SET modules = (
                  (CASE WHEN jsonb_exists_any(roles::jsonb, ARRAY['ROLE_AUDITEUR','ROLE_MANAGER','ROLE_ADMIN','ROLE_SUPER_ADMIN']) THEN '["garanties"]'::jsonb ELSE '[]'::jsonb END)
               || (CASE WHEN jsonb_exists_any(roles::jsonb, ARRAY['ROLE_COMPTABLE','ROLE_ADMIN','ROLE_SUPER_ADMIN']) THEN '["creances"]'::jsonb ELSE '[]'::jsonb END)
               || (CASE WHEN jsonb_exists_any(roles::jsonb, ARRAY['ROLE_COMPTABLE','ROLE_MANAGER','ROLE_ADMIN','ROLE_SUPER_ADMIN']) THEN '["recouvrement"]'::jsonb ELSE '[]'::jsonb END)
               || (CASE WHEN jsonb_exists_any(roles::jsonb, ARRAY['ROLE_MANAGER','ROLE_AUDITEUR','ROLE_COMPTABLE','ROLE_ADMIN','ROLE_SUPER_ADMIN']) THEN '["bonus_eco"]'::jsonb ELSE '[]'::jsonb END)
            )::json
            SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE shared.users DROP COLUMN IF EXISTS modules');
    }
}
