<?php

namespace App\Controller;

use App\Entity\Reservation;
use App\Repository\ReservationRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;
use Doctrine\ORM\EntityManagerInterface;
use App\Repository\VoitureRepository;
use App\Repository\UserRepository;

#[Route('/payment')]
#[IsGranted('ROLE_USER')]
class PaymentController extends AbstractController
{
    /**
     * Show payment form for a pending reservation stored in session.
     */
    #[Route('/checkout/{id}', name: 'app_payment_checkout', methods: ['GET', 'POST'])]
    public function checkout(
        int $id,
        Request $request,
        ReservationRepository $reservationRepository,
        EntityManagerInterface $em,
        MailerInterface $mailer
    ): Response {
        $reservation = $reservationRepository->find($id);

        if (!$reservation) {
            $this->addFlash('error', 'Réservation introuvable.');
            return $this->redirectToRoute('app_reservation_index');
        }

        // Ensure only the owner (or admin) can pay
        if (!$this->isGranted('ROLE_ADMIN') && $reservation->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        if ($reservation->getStatut() === 'confirmed') {
            return $this->redirectToRoute('app_payment_confirmation', ['id' => $reservation->getId()]);
        }

        $errors = [];
        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('payment_' . $reservation->getId(), $request->request->getString('_token'))) {
                throw $this->createAccessDeniedException('Jeton CSRF invalide.');
            }
            $cardNumber    = preg_replace('/\s+/', '', $request->request->get('card_number', ''));
            $cardHolder    = trim($request->request->get('card_holder', ''));
            $expiryMonth   = trim($request->request->get('expiry_month', ''));
            $expiryYear    = trim($request->request->get('expiry_year', ''));
            $cvv           = trim($request->request->get('cvv', ''));
            $billingAddr   = trim($request->request->get('billing_address', ''));
            $billingCity   = trim($request->request->get('billing_city', ''));
            $billingZip    = trim($request->request->get('billing_zip', ''));

            // ---- Validation ----
            if (!preg_match('/^\d{13,19}$/', $cardNumber)) {
                $errors['card_number'] = 'Numéro de carte invalide (13–19 chiffres requis).';
            }
            if (strlen($cardHolder) < 3) {
                $errors['card_holder'] = 'Nom du titulaire requis.';
            }
            if (!$expiryMonth || !$expiryYear) {
                $errors['expiry'] = 'Date d\'expiration invalide.';
            } else {
                $now = new \DateTime();
                $expiry = \DateTime::createFromFormat('m/Y', $expiryMonth . '/' . $expiryYear);
                if (!$expiry || $expiry < $now) {
                    $errors['expiry'] = 'La carte est expirée ou la date est invalide.';
                }
            }
            if (!preg_match('/^\d{3,4}$/', $cvv)) {
                $errors['cvv'] = 'CVV invalide (3 ou 4 chiffres requis).';
            }
            if (strlen($billingAddr) < 5) {
                $errors['billing_address'] = 'Adresse de facturation requise.';
            }
            if (strlen($billingCity) < 2) {
                $errors['billing_city'] = 'Ville requise.';
            }
            if (!preg_match('/^\d{4,10}$/', $billingZip)) {
                $errors['billing_zip'] = 'Code postal invalide.';
            }

            if (empty($errors)) {
                // Mark reservation as confirmed (payment step done)
                $reservation->setStatut('confirmed');
                $em->flush();

                // Send confirmation email
                $this->sendConfirmationEmail($mailer, $reservation);

                $this->addFlash('success', 'Paiement accepté ! Votre réservation est confirmée. Un email de confirmation a été envoyé.');
                return $this->redirectToRoute('app_payment_confirmation', ['id' => $reservation->getId()]);
            }
        }

        return $this->render('payment/checkout.html.twig', [
            'reservation' => $reservation,
            'errors'      => $errors,
            'months'      => range(1, 12),
            'years'       => range((int) date('Y'), (int) date('Y') + 15),
        ]);
    }

    /**
     * Confirmation page after successful payment.
     */
    #[Route('/confirmation/{id}', name: 'app_payment_confirmation', methods: ['GET'])]
    public function confirmation(
        int $id,
        ReservationRepository $reservationRepository
    ): Response {
        $reservation = $reservationRepository->find($id);

        if (!$reservation) {
            return $this->redirectToRoute('app_reservation_index');
        }

        if (!$this->isGranted('ROLE_ADMIN') && $reservation->getClient() !== $this->getUser()) {
            throw $this->createAccessDeniedException();
        }

        return $this->render('payment/confirmation.html.twig', [
            'reservation' => $reservation,
        ]);
    }

    private function sendConfirmationEmail(MailerInterface $mailer, Reservation $reservation): void
    {
        try {
            $client    = $reservation->getClient();
            $voiture   = $reservation->getVoiture();
            $days      = $reservation->getDateFin()->diff($reservation->getDateDebut())->days + 1;

            $htmlBody = $this->renderView('emails/reservation_confirmation.html.twig', [
                'reservation' => $reservation,
                'client'      => $client,
                'voiture'     => $voiture,
                'days'        => $days,
            ]);

            $email = (new Email())
                ->from(new Address('noreply@carrent.tn', 'CarRent — Location de Voitures'))
                ->to(new Address($client->getEmail(), $client->getNom()))
                ->subject('✅ Confirmation de votre réservation #' . $reservation->getId() . ' — CarRent')
                ->html($htmlBody);

            $mailer->send($email);
        } catch (\Throwable $e) {
            // Email failure should not block the confirmation flow
            // Log or silently fail
        }
    }
}
