<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20230117134142 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workspace ACL tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        // Remove all disconnected workspace details entities or the foreign table constraint will fail
        $this->addSql('DELETE FROM shel_neos_workspacemodule_domain_model_workspacedetails d WHERE d.workspacename NOT IN (SELECT name FROM neos_contentrepository_domain_model_workspace)');

        $this->addSql('CREATE TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join (workspacemodule_workspacedetails VARCHAR(40) NOT NULL, neos_user VARCHAR(40) NOT NULL, PRIMARY KEY(workspacemodule_workspacedetails, neos_user))');
        $this->addSql('CREATE INDEX IDX_9EE667F39AF30FF3 ON shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join (workspacemodule_workspacedetails)');
        $this->addSql('CREATE INDEX IDX_9EE667F3C7FF26B ON shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join (neos_user)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F39AF30FF3 FOREIGN KEY (workspacemodule_workspacedetails) REFERENCES shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F3C7FF26B FOREIGN KEY (neos_user) REFERENCES neos_neos_domain_model_user (persistence_object_identifier) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails RENAME COLUMN workspacename TO workspace');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name) NOT DEFERRABLE INITIALLY IMMEDIATE');
        $this->addSql('CREATE UNIQUE INDEX flow_identity_shel_neos_workspacemodule_domain_model_work_e2fe7 ON shel_neos_workspacemodule_domain_model_workspacedetails (workspace)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('DROP TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP CONSTRAINT FK_923CCEA8D940019');
        $this->addSql('DROP INDEX flow_identity_shel_neos_workspacemodule_domain_model_work_e2fe7');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails RENAME COLUMN workspace TO workspacename');
    }
}
