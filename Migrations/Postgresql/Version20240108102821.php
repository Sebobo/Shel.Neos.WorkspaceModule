<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240108102821 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete workspace details when user including their workspaces are deleted';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP CONSTRAINT FK_923CCEA8D940019');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER workspace DROP NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP CONSTRAINT fk_923ccea8d940019');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER workspace SET NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT fk_923ccea8d940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name) NOT DEFERRABLE INITIALLY IMMEDIATE');
    }
}
