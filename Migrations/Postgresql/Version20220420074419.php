<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Migrations\AbortMigrationException;

class Version20220420074419 extends AbstractMigration
{

    /**
     * @return string
     */
    public function getDescription(): string
    {
        return '';
    }

    /**
     * @throws AbortMigrationException
     */
    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER lastchangeddate DROP NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER lastchangedby DROP NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER creator DROP NOT NULL');
    }

    /**
     * @throws AbortMigrationException
     */
    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER lastchangeddate SET NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER lastchangedby SET NOT NULL');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ALTER creator SET NOT NULL');
    }
}
