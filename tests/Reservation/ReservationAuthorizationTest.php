<?php

namespace App\Tests\Reservation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirements 3 & 4: ownership and authorization - a user may view, edit
 * or cancel (delete) only their own reservations, an admin may act on any
 * of them, and the reservation list itself must never list another user's
 * bookings.
 *
 * This duplicates, deliberately, some of what test/security's
 * IdorPreventionTest covers - that branch is unmerged, and this task asked
 * for comprehensive reservation-system coverage on its own branch. The two
 * will simply both exist once merged; nothing here depends on that other
 * branch.
 */
class ReservationAuthorizationTest extends ReservationTestCase
{
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

    public function testOwnerCanEditTheirOwnReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($owner);
        $client->request('GET', '/reservation/'.$reservation->getId().'/edit');

        self::assertResponseIsSuccessful();
    }

    public function testOwnerCanCancelTheirOwnReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/reservation');
        $token = $crawler->filter('form[action="/reservation/'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/reservation/'.$id, ['_token' => $token]);

        $em->clear();
        self::assertNull($em->getRepository(\App\Entity\Reservation::class)->find($id));
    }

    public function testAnotherUserCannotViewIt(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $other = $this->makeUser($em, 'other', 'OtherPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($other);
        $client->request('GET', '/reservation/'.$reservation->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherUserCannotEditIt(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $other = $this->makeUser($em, 'other', 'OtherPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($other);
        $client->request('GET', '/reservation/'.$reservation->getId().'/edit');

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnotherUserCannotCancelIt(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $other = $this->makeUser($em, 'other', 'OtherPass123');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        // checkReservationAccess() runs before the CSRF check, so the token
        // value is irrelevant here - a real attacker would not have the
        // genuine one anyway (it is only rendered on the owner's own index
        // page).
        $client->loginUser($other);
        $client->request('POST', '/reservation/'.$id, ['_token' => 'irrelevant-value']);

        self::assertResponseStatusCodeSame(403);

        $em->clear();
        self::assertNotNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($id),
            'the reservation must survive the rejected cancel attempt',
        );
    }

    public function testAdminCanViewEditAndCancelAnyReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $admin = $this->makeUser($em, 'adminuser', 'AdminPass123', 'ROLE_ADMIN');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        $client->loginUser($admin);

        $client->request('GET', '/reservation/'.$id);
        self::assertResponseIsSuccessful('admin must be able to view any reservation');

        $client->request('GET', '/reservation/'.$id.'/edit');
        self::assertResponseIsSuccessful('admin must be able to edit any reservation');

        $crawler = $client->request('GET', '/reservation');
        $token = $crawler->filter('form[action="/reservation/'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/reservation/'.$id, ['_token' => $token]);

        $em->clear();
        self::assertNull(
            $em->getRepository(\App\Entity\Reservation::class)->find($id),
            'admin must be able to cancel any reservation',
        );
    }

    public function testIndexListsOnlyOwnReservationsForARegularUser(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $other = $this->makeUser($em, 'other', 'OtherPass123');
        $voitureOwner = $this->makeVoiture($em);
        $voitureOther = $this->makeVoiture($em);
        $ownerReservation = $this->makeReservation($em, $owner, $voitureOwner);
        $otherReservation = $this->makeReservation($em, $other, $voitureOther);

        $client->loginUser($other);
        $crawler = $client->request('GET', '/reservation');

        self::assertResponseIsSuccessful();
        self::assertCount(
            1,
            $crawler->filter('a[href="/reservation/'.$otherReservation->getId().'"]'),
            'the user\'s own reservation must be listed',
        );
        self::assertCount(
            0,
            $crawler->filter('a[href="/reservation/'.$ownerReservation->getId().'"]'),
            'another user\'s reservation must not be listed',
        );
    }

    public function testIndexListsAllReservationsForAnAdmin(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $admin = $this->makeUser($em, 'adminuser', 'AdminPass123', 'ROLE_ADMIN');
        $voiture = $this->makeVoiture($em);
        $reservation = $this->makeReservation($em, $owner, $voiture);

        $client->loginUser($admin);
        $crawler = $client->request('GET', '/reservation');

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('a[href="/reservation/'.$reservation->getId().'"]'));
    }
}
