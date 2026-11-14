<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515000001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add statut column to reservation table';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE reservation ADD COLUMN statut VARCHAR(50) NOT NULL DEFAULT 'pending'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('CREATE TEMPORARY TABLE __temp__reservation AS SELECT id, date_debut, date_fin, prix_total, client_id, voiture_id FROM reservation');
        $this->addSql('DROP TABLE reservation');
        $this->addSql('CREATE TABLE reservation (id INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL, date_debut DATE NOT NULL, date_fin DATE NOT NULL, prix_total DOUBLE PRECISION NOT NULL, client_id INTEGER NOT NULL, voiture_id INTEGER NOT NULL)');
        $this->addSql('INSERT INTO reservation SELECT id, date_debut, date_fin, prix_total, client_id, voiture_id FROM __temp__reservation');
        $this->addSql('DROP TABLE __temp__reservation');
    }
}
