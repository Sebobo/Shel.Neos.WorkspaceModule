<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20240108102400 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Delete workspace details when user including their workspaces are deleted';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP FOREIGN KEY FK_923CCEA8D940019');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'mysql', 'Migration can only be executed safely on \'mysql\'.');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP FOREIGN KEY FK_923CCEA8D940019');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD CONSTRAINT FK_923CCEA8D940019 FOREIGN KEY (workspace) REFERENCES neos_contentrepository_domain_model_workspace (name)');
    }
}
