<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260915095137 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create webhook events with unique event IDs and a customer timeline index.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE webhook_event (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, event_id VARCHAR(255) NOT NULL, customer_id VARCHAR(255) NOT NULL, priority SMALLINT NOT NULL, created_at DATETIME NOT NULL, payload CLOB DEFAULT NULL, received_at DATETIME NOT NULL)');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_B17EEFDE71F7E88B ON webhook_event (event_id)');
        $this->addSql('CREATE INDEX idx_customer_created ON webhook_event (customer_id, created_at)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE webhook_event');
    }
}
