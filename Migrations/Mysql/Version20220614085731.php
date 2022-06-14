<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220614085731 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds acl table for workspaces';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('CREATE TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join (workspacemodule_workspacedetails VARCHAR(40) NOT NULL, neos_user VARCHAR(40) NOT NULL, INDEX IDX_9EE667F39AF30FF3 (workspacemodule_workspacedetails), INDEX IDX_9EE667F3C7FF26B (neos_user), PRIMARY KEY(workspacemodule_workspacedetails, neos_user)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F39AF30FF3 FOREIGN KEY (workspacemodule_workspacedetails) REFERENCES shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F3C7FF26B FOREIGN KEY (neos_user) REFERENCES neos_neos_domain_model_user (persistence_object_identifier)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('CREATE TABLE flownative_tokenauthentication_security_model_hashandroles (hash VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, roleshash VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, roles LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci` COMMENT \'(DC2Type:json_array)\', settings LONGTEXT CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci` COMMENT \'(DC2Type:json_array)\', PRIMARY KEY(hash)) DEFAULT CHARACTER SET utf8 COLLATE `utf8_unicode_ci` ENGINE = InnoDB COMMENT = \'\' ');
        $this->addSql('DROP TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join');
    }
}
