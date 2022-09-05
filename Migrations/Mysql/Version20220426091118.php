<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Platforms\MySqlPlatform;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20220426091118 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Adds table for tracking workspace activity';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('CREATE TABLE shel_neos_workspacemodule_domain_model_workspacedetails (persistence_object_identifier VARCHAR(40) NOT NULL, lastchangeddate DATETIME DEFAULT NULL, lastchangedby VARCHAR(255) DEFAULT NULL, workspacename VARCHAR(255) NOT NULL, creator VARCHAR(255) DEFAULT NULL, PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof MySqlPlatform,
            "Migration can only be executed safely on 'MySqlPlatform'."
        );

        $this->addSql('DROP TABLE shel_neos_workspacemodule_domain_model_workspacedetails');
    }
}
