<?php

namespace App\Entity;

use App\Repository\ReservationRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: ReservationRepository::class)]
class Reservation
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de début est obligatoire.')]
    #[Assert\GreaterThanOrEqual('today', message: 'La date de début doit être aujourd\'hui ou dans le futur.')]
    private ?\DateTime $dateDebut = null;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    #[Assert\NotBlank(message: 'La date de fin est obligatoire.')]
    #[Assert\GreaterThan(propertyPath: 'dateDebut', message: 'La date de fin doit être après la date de début.')]
    private ?\DateTime $dateFin = null;

    #[ORM\Column]
    private ?float $prixTotal = null;

    #[ORM\ManyToOne(inversedBy: 'reservations')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'Le client est obligatoire.')]
    private ?User $client = null;

    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'La voiture est obligatoire.')]
    private ?Voiture $voiture = null;

    #[ORM\Column(length: 50, options: ['default' => 'pending'])]
    private string $statut = 'pending';

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDateDebut(): ?\DateTime
    {
        return $this->dateDebut;
    }

    public function setDateDebut(\DateTime $dateDebut): static
    {
        $this->dateDebut = $dateDebut;

        return $this;
    }

    public function getDateFin(): ?\DateTime
    {
        return $this->dateFin;
    }

    public function setDateFin(\DateTime $dateFin): static
    {
        $this->dateFin = $dateFin;

        return $this;
    }

    public function getPrixTotal(): ?float
    {
        return $this->prixTotal;
    }

    public function setPrixTotal(float $prixTotal): static
    {
        $this->prixTotal = $prixTotal;

        return $this;
    }

    public function getClient(): ?User
    {
        return $this->client;
    }

    public function setClient(User $client): static
    {
        $this->client = $client;

        return $this;
    }

    public function getVoiture(): ?Voiture
    {
        return $this->voiture;
    }

    public function setVoiture(?Voiture $voiture): static
    {
        $this->voiture = $voiture;

        return $this;
    }

    public function getStatut(): string
    {
        return $this->statut;
    }

    public function setStatut(string $statut): static
    {
        $this->statut = $statut;
        return $this;
    }
}
