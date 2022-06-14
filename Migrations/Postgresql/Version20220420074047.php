<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20220420074047 extends AbstractMigration
{

    public function getDescription(): string
    {
        return 'Adds creator to workspace details';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails ADD creator VARCHAR(255) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');
        $this->addSql('ALTER TABLE shel_neos_workspacemodule_domain_model_workspacedetails DROP creator');
    }
}
