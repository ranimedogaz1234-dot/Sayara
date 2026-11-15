<?php

namespace App\Tests\Security;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirement 4: CSRF protection on state-changing actions.
 *
 * Each delete action checks isCsrfTokenValid('delete'.$id, ...) against the
 * '_token' request field before doing anything. A missing or wrong token
 * must leave the record untouched; the application silently redirects back
 * rather than erroring, so the test proves protection by asserting the
 * record survives, not by asserting a particular status code.
 */
class CsrfProtectionTest extends SecurityTestCase
{
    public function testReservationDeleteWithoutTokenIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $client->request('POST', '/reservation/'.$id, ['_token' => 'not-a-real-token']);

        $em->clear();
        self::assertNotNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($id),
            'a forged CSRF token must not delete the reservation',
        );
    }

    public function testReservationDeleteWithValidTokenSucceeds(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $token = $this->scrapeDeleteToken($client, $id);
        $client->request('POST', '/reservation/'.$id, ['_token' => $token]);

        $em->clear();
        self::assertNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($id),
            'a genuine, matching CSRF token must be accepted',
        );
    }

    public function testVoitureDeleteWithoutTokenIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->makeUser($em, 'adminuser', 'AdminPass123', 'ROLE_ADMIN');
        $voiture = $this->makeVoiture($em);
        $id = $voiture->getId();

        $client->loginUser($admin);
        $client->request('POST', '/voiture/'.$id, ['_token' => 'wrong-token']);

        $em->clear();
        self::assertNotNull(
            $em->getRepository(\App\Entity\Voiture::class)->find($id),
            'a forged CSRF token must not delete the vehicle',
        );
    }

    public function testUserDeleteWithoutTokenIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $admin = $this->makeUser($em, 'adminuser', 'AdminPass123', 'ROLE_ADMIN');
        $target = $this->makeUser($em, 'target', 'TargetPass123');
        $id = $target->getId();

        $client->loginUser($admin);
        $client->request('POST', '/user/'.$id, ['_token' => 'wrong-token']);

        $em->clear();
        self::assertNotNull(
            $em->getRepository(\App\Entity\User::class)->find($id),
            'a forged CSRF token must not delete the user account',
        );
    }

    /**
     * A token minted for one record must not authorise deleting a different
     * one - the token id is scoped per entity id, not reusable.
     */
    public function testCsrfTokenIsScopedToItsOwnRecord(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservationA = $this->makeReservation($em, $owner, $voiture);
        $reservationB = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($owner);
        $tokenForA = $this->scrapeDeleteToken($client, $reservationA->getId());

        // Attempt to delete B using A's token.
        $client->request('POST', '/reservation/'.$reservationB->getId(), ['_token' => $tokenForA]);

        $em->clear();
        self::assertNotNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($reservationB->getId()),
            'a token minted for one record must not delete another',
        );
    }
}
