<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Form\ReservationType;
use App\Repository\ReservationRepository;
use App\Repository\VoitureRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/reservation')]
#[IsGranted('ROLE_USER')]
final class ReservationController extends AbstractController
{
    private function checkReservationAccess(Reservation $reservation): void
    {
        if (!$this->isGranted('ROLE_ADMIN') && $reservation->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException('Vous n\'avez pas accès à cette réservation.');
        }
    }

    #[Route(name: 'app_reservation_index', methods: ['GET'])]
    public function index(ReservationRepository $reservationRepository): Response
    {
        $user = $this->getUser();

        // Admins see all reservations, users see only their own
        if ($this->isGranted('ROLE_ADMIN')) {
            $reservations = $reservationRepository->findAll();
        } else {
            $reservations = $reservationRepository->findBy(['client' => $user], ['dateDebut' => 'DESC']);
        }

        return $this->render('reservation/index.html.twig', [
            'reservations' => $reservations,
        ]);
    }

    #[Route('/new', name: 'app_reservation_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, VoitureRepository $voitureRepository): Response
    {
        $reservation = new Reservation();
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        // Auto-populate with logged-in user only for non-admins
        if (!$isAdmin) {
            $reservation->setClient($this->getUser());
        }

        // Pre-fill from the "Book Now" link on the catalog/vehicle pages
        // (?voiture=ID&pickupDate=...&returnDate=...). GET-only: this never
        // runs on the POST submission below, so it cannot override or
        // bypass anything the form itself validates.
        if (!$request->isMethod('POST')) {
            $voitureId = $request->query->get('voiture');
            if ($voitureId && $voiture = $voitureRepository->find($voitureId)) {
                if ($voiture->isDisponible()) {
                    $reservation->setVoiture($voiture);
                }
            }
            foreach (['pickupDate' => 'setDateDebut', 'returnDate' => 'setDateFin'] as $param => $setter) {
                $value = $request->query->get($param);
                if ($value) {
                    try {
                        $reservation->{$setter}(new \DateTime($value));
                    } catch (\Exception) {
                        // Ignore an unparsable date - the form's own
                        // validation constraints are the real gate here.
                    }
                }
            }
        }

        $form = $this->createForm(ReservationType::class, $reservation, [
            'is_admin' => $isAdmin,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Ensure non-admin users cannot spoof the client
            if (!$isAdmin) {
                $reservation->setClient($this->getUser());
            }

            // Calculate price based on dates and vehicle price
            $dateDebut = $reservation->getDateDebut();
            $dateFin = $reservation->getDateFin();
            $voiture = $reservation->getVoiture();

            if ($dateDebut && $dateFin && $voiture) {
                $days = $dateFin->diff($dateDebut)->days + 1;
                if ($days < 1) $days = 1;
                $totalPrice = $days * $voiture->getPrixParJour();
                $reservation->setPrixTotal($totalPrice);
            }

            // Mark the car as unavailable
            $reservation->getVoiture()->setDisponible(false);

            $entityManager->persist($reservation);
            $entityManager->flush();

            $this->addFlash('success', 'Réservation créée avec succès ! Veuillez procéder au paiement.');

            // New flow: redirect to payment page (admin skips payment)
            if ($this->isGranted('ROLE_ADMIN')) {
                return $this->redirectToRoute('app_reservation_index', [], Response::HTTP_SEE_OTHER);
            } else {
                return $this->redirectToRoute('app_payment_checkout', ['id' => $reservation->getId()], Response::HTTP_SEE_OTHER);
            }
        }

        return $this->render('reservation/new.html.twig', [
            'reservation' => $reservation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_reservation_show', methods: ['GET'])]
    public function show(Reservation $reservation): Response
    {
        $this->checkReservationAccess($reservation);

        return $this->render('reservation/show.html.twig', [
            'reservation' => $reservation,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_reservation_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Reservation $reservation, EntityManagerInterface $entityManager): Response
    {
        $this->checkReservationAccess($reservation);

        $oldVoiture = $reservation->getVoiture();
        $form = $this->createForm(ReservationType::class, $reservation, [
            'is_admin' => $this->isGranted('ROLE_ADMIN'),
            'current_voiture' => $oldVoiture,
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Recalculate price when dates or car change
            $dateDebut = $reservation->getDateDebut();
            $dateFin = $reservation->getDateFin();
            $voiture = $reservation->getVoiture();

            if ($dateDebut && $dateFin && $voiture) {
                $days = $dateFin->diff($dateDebut)->days + 1;
                if ($days < 1) $days = 1;
                $totalPrice = $days * $voiture->getPrixParJour();
                $reservation->setPrixTotal($totalPrice);
            }

            // Keep availability consistent if the car was changed
            if ($voiture && $oldVoiture && $voiture !== $oldVoiture) {
                $oldVoiture->setDisponible(true);
                $voiture->setDisponible(false);
            }

            $entityManager->flush();
            $this->addFlash('success', 'Réservation modifiée avec succès!');

            return $this->redirectToRoute('app_reservation_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('reservation/edit.html.twig', [
            'reservation' => $reservation,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_reservation_delete', methods: ['POST'])]
    public function delete(Request $request, Reservation $reservation, EntityManagerInterface $entityManager): Response
    {
        $this->checkReservationAccess($reservation);

        if ($this->isCsrfTokenValid('delete'.$reservation->getId(), $request->getPayload()->getString('_token'))) {
            // Restore car availability when reservation is deleted
            $voiture = $reservation->getVoiture();
            $voiture->setDisponible(true);
            $entityManager->remove($reservation);
            $entityManager->flush();
            $this->addFlash('success', 'Réservation supprimée avec succès!');
        }

        return $this->redirectToRoute('app_reservation_index', [], Response::HTTP_SEE_OTHER);
    }
}
