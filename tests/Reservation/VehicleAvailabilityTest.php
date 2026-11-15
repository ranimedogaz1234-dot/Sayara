<?php

namespace App\Tests\Reservation;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Requirement 6: vehicle availability and overlapping reservations.
 *
 * What the application actually implements, confirmed by reading
 * ReservationController, ReservationType and the Voiture/Reservation
 * entities before writing any test here:
 *
 *   - Voiture::$disponible is a single boolean, not a set of booked date
 *     ranges. Creating a reservation sets it to false
 *     (ReservationController::new()); cancelling one sets it back to true
 *     (ReservationController::delete()); changing a reservation's car in
 *     edit() frees the old one and occupies the new one.
 *   - ReservationType's EntityType query_builder for the 'voiture' field
 *     only offers cars where disponible = true (plus the reservation's own
 *     current car, when editing). Symfony's EntityType rejects a submitted
 *     value that is not in that choice list, so picking an unavailable car
 *     fails form validation rather than being silently accepted.
 *
 * What does NOT exist, verified by searching the codebase for any
 * date-range comparison against other reservations for the same car
 * (there is none): true overlap prevention by date. A car is either
 * entirely bookable or entirely not; two reservations for the same car
 * with non-overlapping dates are impossible today, because the first one
 * makes the car unavailable regardless of the dates involved. This is
 * documented here rather than tested as a feature, per this task's
 * instruction not to invent functionality that does not exist. It is a
 * plausible gap worth the user's attention: once the car is freed (by
 * cancelling or confirming-and-later-cancelling), nothing stops a new,
 * date-overlapping reservation from being created against the historical
 * one.
 */
class VehicleAvailabilityTest extends ReservationTestCase
{
    public function testBookingACarMarksItUnavailable(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'renter', 'RenterPass123');
        $voiture = $this->makeVoiture($em, true);
        self::assertTrue($voiture->isDisponible());

        $client->loginUser($user);
        $crawler = $client->request('GET', '/reservation/new');
        $form = $crawler->filter('form')->form();
        $form['reservation[dateDebut]'] = (new \DateTime('+1 day'))->format('Y-m-d');
        $form['reservation[dateFin]'] = (new \DateTime('+3 days'))->format('Y-m-d');
        $form['reservation[voiture]'] = (string) $voiture->getId();
        $client->submit($form);

        $em->clear();
        $refreshed = $em->getRepository(\App\Entity\Voiture::class)->find($voiture->getId());
        self::assertFalse($refreshed->isDisponible());
    }

    /**
     * Once a car is unavailable, ReservationType's query_builder excludes
     * it from the choice list for a *new* reservation, so attempting to
     * submit its id must fail validation rather than create a second,
     * conflicting booking.
     */
    public function testUnavailableCarCannotBeChosenForANewReservation(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $attacker = $this->makeUser($em, 'attacker', 'AttackPass123');
        $voiture = $this->makeVoiture($em, true);
        $this->makeReservation($em, $owner, $voiture); // marks it unavailable

        $client->loginUser($attacker);
        $crawler = $client->request('GET', '/reservation/new');

        self::assertCount(
            0,
            $crawler->filter('select[name="reservation[voiture]"] option[value="'.$voiture->getId().'"]'),
            'an unavailable car must not be offered as a choice',
        );

        // Forge the submission anyway, as if the <option> had been added
        // client-side, to confirm the server rejects it independently of
        // what the rendered <select> offers.
        $form = $crawler->filter('form')->form();
        $form['reservation[dateDebut]'] = (new \DateTime('+5 days'))->format('Y-m-d');
        $form['reservation[dateFin]'] = (new \DateTime('+7 days'))->format('Y-m-d');
        $client->request(
            'POST',
            $form->getUri(),
            array_replace_recursive($form->getPhpValues(), ['reservation' => ['voiture' => (string) $voiture->getId()]]),
        );

        self::assertResponseStatusCodeSame(422, 'a forged choice of an unavailable car must re-render the form with a 422');

        $em->clear();
        self::assertCount(
            1,
            $em->getRepository(\App\Entity\Reservation::class)->findAll(),
            'only the original reservation must exist - the forged one must not be created',
        );
    }

    public function testCancellingAReservationFreesTheCarAgain(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $voiture = $this->makeVoiture($em, true);
        $reservation = $this->makeReservation($em, $owner, $voiture);
        $id = $reservation->getId();

        self::assertFalse($voiture->isDisponible());

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/reservation');
        $token = $crawler->filter('form[action="/reservation/'.$id.'"] input[name="_token"]')->attr('value');
        $client->request('POST', '/reservation/'.$id, ['_token' => $token]);

        $em->clear();
        $refreshed = $em->getRepository(\App\Entity\Voiture::class)->find($voiture->getId());
        self::assertTrue($refreshed->isDisponible(), 'cancelling must make the car bookable again');
    }

    public function testChangingTheCarOnEditFreesTheOldOneAndOccupiesTheNewOne(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $owner = $this->makeUser($em, 'owner', 'OwnerPass123');
        $oldVoiture = $this->makeVoiture($em, true, 30.0);
        $newVoiture = $this->makeVoiture($em, true, 30.0);
        $reservation = $this->makeReservation($em, $owner, $oldVoiture);

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/reservation/'.$reservation->getId().'/edit');
        $form = $crawler->filter('form')->form();
        $form['reservation[voiture]'] = (string) $newVoiture->getId();
        $client->submit($form);

        $em->clear();
        $refreshedOld = $em->getRepository(\App\Entity\Voiture::class)->find($oldVoiture->getId());
        $refreshedNew = $em->getRepository(\App\Entity\Voiture::class)->find($newVoiture->getId());

        self::assertTrue($refreshedOld->isDisponible(), 'the previous car must be freed');
        self::assertFalse($refreshedNew->isDisponible(), 'the newly chosen car must become unavailable');
    }
}
