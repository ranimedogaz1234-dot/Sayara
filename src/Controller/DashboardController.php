<?php

namespace App\Controller;

use App\Repository\VoitureRepository;
use App\Repository\UserRepository;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_ADMIN')]
class DashboardController extends AbstractController
{
    #[Route('/dashboard', name: 'app_dashboard')]
    public function index(
        VoitureRepository $voitureRepository,
        UserRepository $userRepository,
        ReservationRepository $reservationRepository
    ): Response {
        $voitures = $voitureRepository->findAll();
        $nbDisponibles = count(array_filter($voitures, fn($v) => $v->isDisponible()));

        // Build fleet chart data grouped by brand
        $brandStats = [];
        foreach ($voitures as $v) {
            $marque = $v->getMarque();
            if (!isset($brandStats[$marque])) {
                $brandStats[$marque] = ['available' => 0, 'rented' => 0];
            }
            if ($v->isDisponible()) {
                $brandStats[$marque]['available']++;
            } else {
                $brandStats[$marque]['rented']++;
            }
        }
        arsort($brandStats);
        // Show top 10 brands max
        $brandStats = array_slice($brandStats, 0, 10, true);

        $fleetChartData = [
            'labels'    => array_keys($brandStats),
            'available' => array_column(array_values($brandStats), 'available'),
            'rented'    => array_column(array_values($brandStats), 'rented'),
        ];

        // Total revenues
        $reservations = $reservationRepository->findAll();
        $totalRevenus = array_reduce($reservations, fn($carry, $r) => $carry + $r->getPrixTotal(), 0);

        return $this->render('dashboard/index.html.twig', [
            'nb_voitures'            => count($voitures),
            'nb_voitures_dispo'      => $nbDisponibles,
            'nb_clients'             => count($userRepository->findAll()),
            'nb_reservations'        => count($reservations),
            'total_revenus'          => $totalRevenus,
            'fleet_chart_data'       => $fleetChartData,
            'dernieres_reservations' => $reservationRepository->findBy([], ['id' => 'DESC'], 5),
        ]);
    }
}
