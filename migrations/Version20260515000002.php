<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260515000002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add more cars to the voiture table';
    }

    public function up(Schema $schema): void
    {
        $cars = [
            ['Toyota',      'Corolla',       85.00,  1, null],
            ['Toyota',      'RAV4',          120.00, 1, null],
            ['Hyundai',     'Tucson',        110.00, 1, null],
            ['Hyundai',     'Elantra',       80.00,  1, null],
            ['Ford',        'Focus',         75.00,  1, null],
            ['Ford',        'Explorer',      150.00, 1, null],
            ['Volkswagen',  'Golf',          90.00,  1, null],
            ['Volkswagen',  'Tiguan',        130.00, 1, null],
            ['BMW',         'Série 3',       200.00, 1, null],
            ['BMW',         'X5',            280.00, 1, null],
            ['Mercedes',    'Classe C',      220.00, 1, null],
            ['Mercedes',    'GLE',           300.00, 1, null],
            ['Audi',        'A4',            210.00, 1, null],
            ['Audi',        'Q7',            320.00, 1, null],
            ['Renault',     'Clio',          65.00,  1, null],
            ['Renault',     'Kadjar',        100.00, 1, null],
            ['Peugeot',     '308',           70.00,  1, null],
            ['Peugeot',     '3008',          115.00, 1, null],
            ['Kia',         'Sportage',      105.00, 1, null],
            ['Kia',         'Stinger',       170.00, 1, null],
            ['Seat',        'Leon',          78.00,  1, null],
            ['Skoda',       'Octavia',       82.00,  1, null],
            ['Honda',       'Civic',         88.00,  1, null],
            ['Honda',       'CR-V',          125.00, 1, null],
            ['Dacia',       'Sandero',       55.00,  1, null],
            ['Dacia',       'Duster',        85.00,  1, null],
            ['Nissan',      'Qashqai',       108.00, 1, null],
            ['Nissan',      'X-Trail',       135.00, 1, null],
            ['Jeep',        'Compass',       140.00, 1, null],
            ['Jeep',        'Grand Cherokee',260.00, 1, null],
        ];

        foreach ($cars as $car) {
            $this->addSql(
                "INSERT OR IGNORE INTO voiture (marque, modele, prix_par_jour, disponible, image) VALUES (?, ?, ?, ?, ?)",
                $car
            );
        }
    }

    public function down(Schema $schema): void
    {
        $marques = ['Toyota','Hyundai','Ford','Volkswagen','BMW','Mercedes','Audi','Renault','Peugeot','Kia','Seat','Skoda','Honda','Dacia','Nissan','Jeep'];
        foreach ($marques as $marque) {
            $this->addSql("DELETE FROM voiture WHERE marque = ?", [$marque]);
        }
    }
}
