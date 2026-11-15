<?php

namespace App\Tests\Reservation;

use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Requirements 1 & 2: creating a valid reservation, and rejecting invalid
 * dates and inputs. Submits the real /reservation/new form, exactly as a
 * browser would.
 */
class ReservationCreationTest extends ReservationTestCase
{
    private function submitNewReservation(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        int $voitureId,
        string $dateDebut,
        string $dateFin,
    ): \Symfony\Component\DomCrawler\Crawler {
        $crawler = $client->request('GET', '/reservation/new');
        $form = $crawler->filter('form')->form();
        $form['reservation[dateDebut]'] = $dateDebut;
        $form['reservation[dateFin]'] = $dateFin;
        $form['reservation[voiture]'] = (string) $voitureId;

        return $client->submit($form);
    }

    public function testValidReservationIsCreatedAndCarMarkedUnavailable(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'renter', 'RenterPass123');
        $voiture = $this->makeVoiture($em, true, 40.0);

        $client->loginUser($user);
        $this->submitNewReservation(
            $client,
            $voiture->getId(),
            (new \DateTime('+1 day'))->format('Y-m-d'),
            (new \DateTime('+3 days'))->format('Y-m-d'),
        );

        // Non-admins are redirected straight to the payment step.
        self::assertResponseRedirects();

        $em->clear();
        $reservations = $em->getRepository(\App\Entity\Reservation::class)->findAll();
        self::assertCount(1, $reservations);

        $reservation = $reservations[0];
        self::assertSame($user->getId(), $reservation->getClient()->getId());
        self::assertSame('pending', $reservation->getStatut());

        $refreshedVoiture = $em->getRepository(\App\Entity\Voiture::class)->find($voiture->getId());
        self::assertFalse($refreshedVoiture->isDisponible(), 'the car must be marked unavailable once booked');
    }

    /**
     * ReservationController::new() computes
     * (dateFin->diff(dateDebut)->days + 1) * prixParJour. A 3-day span
     * (+1 day to +3 days inclusive of both ends by this formula) at
     * 40.0/day must total 3 * 40.0.
     */
    public function testPriceIsCalculatedFromDatesAndDailyRate(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'renter', 'RenterPass123');
        $voiture = $this->makeVoiture($em, true, 40.0);

        $client->loginUser($user);
        $this->submitNewReservation(
            $client,
            $voiture->getId(),
            (new \DateTime('+1 day'))->format('Y-m-d'),
            (new \DateTime('+3 days'))->format('Y-m-d'),
        );

        $em->clear();
        $reservation = $em->getRepository(\App\Entity\Reservation::class)->findAll()[0];

        // diff(+1 day, +3 days) = 2 days -> (2 + 1) * 40.0 = 120.0
        self::assertSame(120.0, $reservation->getPrixTotal());
    }

    public function testNonAdminCannotChooseAnotherUserAsClient(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $renter = $this->makeUser($em, 'renter', 'RenterPass123');
        $otherUser = $this->makeUser($em, 'other', 'OtherPass123');
        $voiture = $this->makeVoiture($em, true);

        $client->loginUser($renter);
        $crawler = $client->request('GET', '/reservation/new');

        // The client field must not even be rendered for a non-admin -
        // ReservationType only adds it when is_admin is true.
        self::assertCount(0, $crawler->filter('select[name="reservation[client]"], input[name="reservation[client]"]'));

        $this->submitNewReservation(
            $client,
            $voiture->getId(),
            (new \DateTime('+1 day'))->format('Y-m-d'),
            (new \DateTime('+3 days'))->format('Y-m-d'),
        );

        $em->clear();
        $reservation = $em->getRepository(\App\Entity\Reservation::class)->findAll()[0];
        self::assertSame($renter->getId(), $reservation->getClient()->getId(), 'the reservation must belong to the logged-in user, not otherUser');
        self::assertNotSame($otherUser->getId(), $reservation->getClient()->getId());
    }

    #[DataProvider('provideInvalidDateCombinations')]
    public function testInvalidDatesAreRejected(string $label, \Closure $dates): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'renter', 'RenterPass123');
        $voiture = $this->makeVoiture($em, true);

        [$dateDebut, $dateFin] = $dates();

        $client->loginUser($user);
        $client->followRedirects(false);
        $this->submitNewReservation($client, $voiture->getId(), $dateDebut, $dateFin);

        // AbstractController::render() auto-sets 422 whenever a submitted
        // Form parameter is invalid (vendor/symfony/framework-bundle/Controller/
        // AbstractController.php, doRender()) - this is Symfony's own
        // documented behaviour, not an app bug.
        self::assertResponseStatusCodeSame(422, sprintf('"%s" should re-render the form with a 422, not redirect', $label));

        $em->clear();
        self::assertCount(
            0,
            $em->getRepository(\App\Entity\Reservation::class)->findAll(),
            sprintf('"%s" must not create a reservation', $label),
        );
    }

    /**
     * @return iterable<string, array{string, \Closure}>
     */
    public static function provideInvalidDateCombinations(): iterable
    {
        yield 'end date before start date' => ['end date before start date', static fn () => [
            (new \DateTime('+5 days'))->format('Y-m-d'),
            (new \DateTime('+1 day'))->format('Y-m-d'),
        ]];

        yield 'end date equal to start date' => ['end date equal to start date', static fn () => [
            (new \DateTime('+2 days'))->format('Y-m-d'),
            (new \DateTime('+2 days'))->format('Y-m-d'),
        ]];

        yield 'start date in the past' => ['start date in the past', static fn () => [
            (new \DateTime('-2 days'))->format('Y-m-d'),
            (new \DateTime('+2 days'))->format('Y-m-d'),
        ]];
    }

    public function testStartDateOfTodayIsAccepted(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $user = $this->makeUser($em, 'renter', 'RenterPass123');
        $voiture = $this->makeVoiture($em, true);

        $client->loginUser($user);
        $this->submitNewReservation(
            $client,
            $voiture->getId(),
            (new \DateTime('today'))->format('Y-m-d'),
            (new \DateTime('+2 days'))->format('Y-m-d'),
        );

        // GreaterThanOrEqual('today') allows today, not only strictly future dates.
        self::assertResponseRedirects();
    }

    public function testMissingVehicleSelectionIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'renter', 'RenterPass123');

        $client->loginUser($user);
        $crawler = $client->request('GET', '/reservation/new');
        $form = $crawler->filter('form')->form();
        $form['reservation[dateDebut]'] = (new \DateTime('+1 day'))->format('Y-m-d');
        $form['reservation[dateFin]'] = (new \DateTime('+3 days'))->format('Y-m-d');
        // reservation[voiture] left at its placeholder ("-- Choisir --").

        $client->submit($form);

        self::assertResponseStatusCodeSame(422, 'submitting with no vehicle chosen must re-render the form with a 422');

        $em->clear();
        self::assertCount(0, $em->getRepository(\App\Entity\Reservation::class)->findAll());
    }
}
