<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220615074317 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add workspace ACL tables';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->skipIf(
            !\array_key_exists('neos_contentrepository_domain_model_workspace', $this->sm->listTableNames()),
            'These migrations are not supported in Neos 9'
        );

        // Remove all disconnected workspace details entities or the foreign table constraint will fail
        $this->addSql('DELETE FROM shel_neos_workspacemodule_domain_model_workspacedetails WHERE workspacename NOT IN (SELECT name FROM neos_contentrepository_domain_model_workspace)');

        $this->addSql('CREATE TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join (workspacemodule_workspacedetails VARCHAR(40) NOT NULL, neos_user VARCHAR(40) NOT NULL, INDEX IDX_9EE667F39AF30FF3 (workspacemodule_workspacedetails), INDEX IDX_9EE667F3C7FF26B (neos_user), PRIMARY KEY(workspacemodule_workspacedetails, neos_user)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F39AF30FF3 FOREIGN KEY (workspacemodule_workspacedetails) REFERENCES shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F3C7FF26B FOREIGN KEY (neos_user) REFERENCES neos_neos_domain_model_user (persistence_object_identifier)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD workspace VARCHAR(255) DEFAULT NULL, DROP workspacename');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name)');
        $this->addSql('CREATE UNIQUE INDEX flow_identity_shel_neos_workspacemodule_domain_model_work_e2fe7 ON shel_neos_workspacemodule_domain_model_workspacedetails (workspace)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $tables = $this->sm->listTableNames();
        if (\array_key_exists('shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join', $tables)) {
            $this->addSql('DROP TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join');
        }

        if (\array_key_exists('shel_neos_workspacemodule_domain_model_workspacedetails', $tables)) {
            $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP FOREIGN KEY FK_923CCEA8D940019');
            $this->addSql('DROP INDEX flow_identity_shel_neos_workspacemodule_domain_model_work_e2fe7 ON shel_neos_workspacemodule_domain_model_workspacedetails');
            $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD workspacename VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, DROP workspace');
        }
    }
}
