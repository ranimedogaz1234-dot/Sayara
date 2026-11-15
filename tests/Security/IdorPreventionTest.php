<?php

namespace App\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirement 3: IDOR prevention for reservations and payment records.
 *
 * Vehicles (Voiture) carry no owner - any admin may see any car by design -
 * so IDOR does not apply to them; their protection is role-based and is
 * covered by RoleBasedAccessTest instead. Reservations and payment records
 * belong to a specific client, so a second authenticated user guessing
 * another user's {id} must be rejected with 403, not served the record.
 */
class IdorPreventionTest extends SecurityTestCase
{
    public function testUserCannotViewAnotherUsersReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($attacker);
        $client->request('GET', '/reservation/'.$reservation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCannotEditAnotherUsersReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($attacker);
        $client->request('GET', '/reservation/'.$reservation->getId().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCannotDeleteAnotherUsersReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $reservationId = $reservation->getId();

        $client->loginUser($attacker);
        // ReservationController::delete() calls checkReservationAccess()
        // before checking the CSRF token, so the token's actual value is
        // irrelevant here - a real attacker would not have access to the
        // victim's genuine token (it is only rendered on the owner's own
        // index page) and this proves it would not help them even if they
        // somehow obtained or guessed one. CSRF protection itself is
        // covered separately in CsrfProtectionTest.
        $client->request('POST', '/reservation/'.$reservationId, ['_token' => 'irrelevant-value']);

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        $stillExists = $em->getRepository(\App\Entity\Reservation::class)->find($reservationId);
        self::assertNotNull($stillExists, 'the reservation must survive the rejected delete attempt');
    }

    public function testOwnerCanViewTheirOwnReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($owner);
        $client->request('GET', '/reservation/'.$reservation->getId());

        self::assertResponseIsSuccessful();
    }

    public function testAdminCanViewAnyReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $admin = $this->makeUser($em, 'adminuser', 'AdminPass123', 'ROLE_ADMIN');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($admin);
        $client->request('GET', '/reservation/'.$reservation->getId());

        self::assertResponseIsSuccessful();
    }

    public function testUserCannotViewAnotherUsersPaymentConfirmation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($attacker);
        $client->request('GET', '/payment/confirmation/'.$reservation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testUserCannotOpenAnotherUsersCheckoutPage(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($attacker);
        $client->request('GET', '/payment/checkout/'.$reservation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testOwnerCanOpenTheirOwnCheckoutPage(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($owner);
        $client->request('GET', '/payment/checkout/'.$reservation->getId());

        self::assertResponseIsSuccessful();
    }

    /**
     * The reservation index must never leak another user's bookings, even
     * though the route itself requires no {id}.
     */
    public function testReservationIndexOnlyShowsOwnReservations(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $other = $this->makeUser($em, 'other', 'OtherPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($other);
        $crawler = $client->request('GET', '/reservation');

        self::assertResponseIsSuccessful();
        self::assertCount(
            0,
            $crawler->filter('a[href="/reservation/'.$reservation->getId().'"]'),
            'the index must not link to another user\'s reservation',
        );
    }
}
