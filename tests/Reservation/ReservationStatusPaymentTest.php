<?php

namespace App\Tests\Reservation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirements 5 & 7: reservation status changes / business rules, and
 * payment-related reservation behaviour.
 *
 * What exists, confirmed by reading PaymentController and the Reservation
 * entity: a reservation is created with statut = 'pending'
 * (Reservation::$statut's column default); PaymentController::checkout()
 * sets it to 'confirmed' once the (simulated) card details pass validation,
 * and PaymentController::confirmation() redirects back to checkout if the
 * reservation is not yet confirmed. There is no 'cancelled' status and no
 * refund logic - deleting a reservation (any status, including
 * 'confirmed') simply removes the row and frees the car; this is a
 * plausible missing business rule and is documented, not invented as a
 * test for functionality that is not there.
 *
 * Email delivery is not asserted: sendConfirmationEmail() wraps the
 * Mailer call in try/catch specifically so a delivery failure never blocks
 * the confirmation flow, and no mail catcher runs in this environment, so
 * this suite only verifies the statut transition and redirect that do not
 * depend on an email actually being delivered.
 */
class ReservationStatusPaymentTest extends ReservationTestCase
{
    private const VALID_CARD = [
        'card_number' => '4242424242424242',
        'card_holder' => 'Test Renter',
        'expiry_month' => '12',
        'billing_address' => '1 Rue de Test',
        'billing_city' => 'Tunis',
        'billing_zip' => '1000',
        'cvv' => '123',
    ];

    private function validExpiryYear(): string
    {
        return (string) ((int) date('Y') + 2);
    }

    public function testNewReservationStartsPending(): void
    {
        $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        self::assertSame('pending', $reservation->getStatut());
    }

    public function testSuccessfulPaymentConfirmsTheReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/payment/checkout/'.$id);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/payment/checkout/'.$id, array_merge(self::VALID_CARD, [
            '_token' => $token,
            'expiry_year' => $this->validExpiryYear(),
        ]));

        self::assertResponseRedirects('/payment/confirmation/'.$id);

        $em->clear();
        $refreshed = $em->getRepository(\App\Entity\Reservation::class)->find($id);
        self::assertSame('confirmed', $refreshed->getStatut());
    }

    public function testInvalidCardDoesNotConfirmTheReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/payment/checkout/'.$id);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/payment/checkout/'.$id, [
            '_token' => $token,
            'card_number' => '123', // too short
            'card_holder' => 'Test Renter',
            'expiry_month' => '12',
            'expiry_year' => $this->validExpiryYear(),
            'cvv' => '123',
            'billing_address' => '1 Rue de Test',
            'billing_city' => 'Tunis',
            'billing_zip' => '1000',
        ]);

        self::assertResponseIsSuccessful('an invalid card must re-render the checkout form, not redirect');

        $em->clear();
        $refreshed = $em->getRepository(\App\Entity\Reservation::class)->find($id);
        self::assertSame('pending', $refreshed->getStatut(), 'a rejected payment must not confirm the reservation');
    }

    public function testExpiredCardIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/payment/checkout/'.$id);
        $token = $crawler->filter('input[name="_token"]')->attr('value');

        $client->request('POST', '/payment/checkout/'.$id, array_merge(self::VALID_CARD, [
            '_token' => $token,
            'expiry_year' => (string) ((int) date('Y') - 1), // already expired
        ]));

        self::assertResponseIsSuccessful();

        $em->clear();
        $refreshed = $em->getRepository(\App\Entity\Reservation::class)->find($id);
        self::assertSame('pending', $refreshed->getStatut());
    }

    /**
     * PaymentController::checkout() redirects to the confirmation page
     * outright once the reservation is already confirmed, instead of
     * re-showing the card form - a already-paid reservation cannot be
     * paid for again through this route.
     */
    public function testCheckoutOfAnAlreadyConfirmedReservationRedirectsToConfirmation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture, statut: 'confirmed');
        $id = $reservation->getId();

        $client->loginUser($owner);
        $client->request('GET', '/payment/checkout/'.$id);

        self::assertResponseRedirects('/payment/confirmation/'.$id);
    }

    public function testConfirmationPageAccessibleOnceConfirmed(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture, statut: 'confirmed');

        $client->loginUser($owner);
        $client->request('GET', '/payment/confirmation/'.$reservation->getId());

        self::assertResponseIsSuccessful();
    }

    /**
     * Documents a real gap rather than asserting it as correct behaviour:
     * deleting a CONFIRMED (paid) reservation is allowed exactly like a
     * pending one - no refund or cancellation-eligibility rule exists.
     * This test records the current behaviour precisely; it does not claim
     * the behaviour is desirable.
     */
    public function testConfirmedReservationCanStillBeCancelledNoRefundRuleExists(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture, statut: 'confirmed');
        $id = $reservation->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/reservation');
        $token = $crawler->filter('form[action="/reservation/'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/reservation/'.$id, ['_token' => $token]);

        $em->clear();
        self::assertNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($id),
            'current behaviour: a confirmed/paid reservation can be deleted exactly like a pending one',
        );
    }
}
