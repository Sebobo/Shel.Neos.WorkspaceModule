<?php
declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\Migrations\AbstractMigration;
use Doctrine\DBAL\Schema\Schema;

class Version20220412145046 extends AbstractMigration
{

    public function getDescription(): string
    {
        return 'Adds table for tracking workspace activity';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        $this->skipIf(
            !\array_key_exists('neos_contentrepository_domain_model_workspace', $this->sm->listTableNames()),
            'These migrations are not supported in Neos 9'
        );

        $this->addSql('CREATE TABLE shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier VARCHAR(40) NOT NULL, lastchangeddate TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, lastchangedby VARCHAR(255) NOT NULL, workspacename VARCHAR(255) NOT NULL, PRIMARY KEY(persistence_object_identifier))');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf($this->connection->getDatabasePlatform()->getName() !== 'postgresql', 'Migration can only be executed safely on "postgresql".');

        if (\array_key_exists('shel_neos_workspacemodule_domain_model_workspacedetails', $this->sm->listTableNames())) {
            $this->addSql('DROP TABLE shel_neos_workspacemodule_domain_model_workspacedetails');
        }
    }
}
