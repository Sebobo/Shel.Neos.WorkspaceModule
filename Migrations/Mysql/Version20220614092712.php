<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220614092712 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Use workspace one-to-one relation as id for workspace details';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join DROP FOREIGN KEY FK_9EE667F39AF30FF3');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP persistence_object_identifier, CHANGE workspacename workspace VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD PRIMARY KEY (workspace)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join CHANGE workspacemodule_workspacedetails workspacemodule_workspacedetails VARCHAR(255) NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F39AF30FF3 FOREIGN KEY (workspacemodule_workspacedetails) REFERENCES shel_neos_workspacemodule_domain_model_workspacedetails (workspace)');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join DROP FOREIGN KEY FK_9EE667F39AF30FF3');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join CHANGE workspacemodule_workspacedetails workspacemodule_workspacedetails VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspace_1536f_acl_join ADD CONSTRAINT FK_9EE667F39AF30FF3 FOREIGN KEY (workspacemodule_workspacedetails) REFERENCES shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier)');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP FOREIGN KEY FK_923CCEA8D940019');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP PRIMARY KEY');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD persistence_object_identifier VARCHAR(40) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`, CHANGE workspace workspacename VARCHAR(255) CHARACTER SET utf8mb4 NOT NULL COLLATE `utf8mb4_unicode_ci`');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD PRIMARY KEY (persistence_object_identifier)');
    }
}
