<?php

namespace App\Repository;

use App\Entity\Voiture;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Voiture>
 */
class VoitureRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Voiture::class);
    }

    /**
     * Distinct brand names currently present in the catalog, used to build
     * the "Our Luxury Brands" section and the catalog brand filter from
     * real data only - never a hardcoded list of brand names that might
     * not actually be in the database.
     *
     * @return string[]
     */
    public function findDistinctBrands(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('DISTINCT v.marque')
            ->orderBy('v.marque', 'ASC')
            ->getQuery()
            ->getScalarResult();

        return array_column($rows, 'marque');
    }

    /**
     * Number of currently available vehicles per brand, for the homepage's
     * brand index (e.g. "Mercedes-Benz — 02"). Brands whose every vehicle is
     * unavailable are omitted, so every listed brand leads to a non-empty
     * catalog page.
     *
     * @return array<string, int>
     */
    public function countAvailableByBrand(): array
    {
        $rows = $this->createQueryBuilder('v')
            ->select('v.marque AS marque, COUNT(v.id) AS total')
            ->where('v.disponible = :dispo')
            ->setParameter('dispo', true)
            ->groupBy('v.marque')
            ->orderBy('v.marque', 'ASC')
            ->getQuery()
            ->getArrayResult();

        return array_column($rows, 'total', 'marque');
    }

    /**
     * Available vehicles (disponible = true), optionally filtered by brand
     * and/or a free-text search across brand and model, and sorted by one
     * of a fixed set of real columns. Everything here maps to an actual
     * Voiture field - no fabricated attributes.
     *
     * @return Voiture[]
     */
    public function searchAvailable(?string $brand, ?string $query, string $sort, ?float $maxPrice = null): array
    {
        $qb = $this->createQueryBuilder('v')
            ->where('v.disponible = :dispo')
            ->setParameter('dispo', true);

        if ($brand) {
            $qb->andWhere('v.marque = :brand')->setParameter('brand', $brand);
        }

        if ($query) {
            $qb->andWhere('v.marque LIKE :q OR v.modele LIKE :q')
                ->setParameter('q', '%'.$query.'%');
        }

        if (null !== $maxPrice) {
            $qb->andWhere('v.prixParJour <= :maxPrice')->setParameter('maxPrice', $maxPrice);
        }

        match ($sort) {
            'price_desc' => $qb->orderBy('v.prixParJour', 'DESC'),
            'name' => $qb->orderBy('v.marque', 'ASC')->addOrderBy('v.modele', 'ASC'),
            default => $qb->orderBy('v.prixParJour', 'ASC'),
        };

        return $qb->getQuery()->getResult();
    }

//    /**
//     * @return Voiture[] Returns an array of Voiture objects
//     */
//    public function findByExampleField($value): array
//    {
//        return $this->createQueryBuilder('v')
//            ->andWhere('v.exampleField = :val')
//            ->setParameter('val', $value)
//            ->orderBy('v.id', 'ASC')
//            ->setMaxResults(10)
//            ->getQuery()
//            ->getResult()
//        ;
//    }

//    public function findOneBySomeField($value): ?Voiture
//    {
//        return $this->createQueryBuilder('v')
//            ->andWhere('v.exampleField = :val')
//            ->setParameter('val', $value)
//            ->getQuery()
//            ->getOneOrNullResult()
//        ;
//    }
}
