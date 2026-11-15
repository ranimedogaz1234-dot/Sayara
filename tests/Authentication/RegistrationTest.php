<?php

namespace App\Tests\Authentication;

use App\Tests\Security\SecurityTestCase;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Registration (signup), duplicate-account rejection, and the password
 * hashing that happens specifically on that code path.
 *
 * Extends the already-merged App\Tests\Security\SecurityTestCase rather
 * than duplicating its fixtures (database-isolation guard, makeUser(),
 * etc.) - tests/Security/* covers login, login throttling, and that a
 * stored password is never plaintext; this file deliberately does not
 * repeat any of that. What it adds is SecurityController::signup()
 * specifically: nothing exercises that controller action anywhere else.
 * tests/Form/UserTypeValidationTest.php already covers the full
 * blank/short/valid password matrix for the 'registration' validation
 * group in isolation (no kernel, no HTTP); this file adds one end-to-end
 * confirmation that the real controller enforces the same rule, not the
 * full matrix again.
 */
class RegistrationTest extends SecurityTestCase
{
    private function submitSignup(
        \Symfony\Bundle\FrameworkBundle\KernelBrowser $client,
        string $username,
        string $email,
        string $password,
    ): \Symfony\Component\DomCrawler\Crawler {
        $crawler = $client->request('GET', '/signup');
        $form = $crawler->filter('form')->form();
        $form['user[username]'] = $username;
        $form['user[nom]'] = 'Test Nom';
        $form['user[email]'] = $email;
        $form['user[password]'] = $password;

        return $client->submit($form);
    }

    public function testValidSignupCreatesARegularUserWithAHashedPassword(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        $this->submitSignup($client, 'newrenter', 'newrenter@example.test', 'NewPass123');

        self::assertResponseRedirects('/login');

        $em->clear();
        $user = $em->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'newrenter']);
        self::assertNotNull($user);
        self::assertSame('ROLE_USER', $user->getRole(), 'signup must never grant ROLE_ADMIN');
        self::assertNotSame('NewPass123', $user->getPassword(), 'the password must not be stored in clear text');
        self::assertTrue($hasher->isPasswordValid($user, 'NewPass123'));
    }

    public function testDuplicateUsernameIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'taken', 'ExistingPass123');

        $this->submitSignup($client, 'taken', 'fresh-email@example.test', 'NewPass123');

        // The controller re-renders the form with a flash error rather than
        // redirecting or returning a form-validation 422.
        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertCount(
            1,
            $em->getRepository(\App\Entity\User::class)->findBy(['username' => 'taken']),
            'no second account must be created for a duplicate username',
        );
    }

    public function testDuplicateEmailIsRejected(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $this->makeUser($em, 'original', 'ExistingPass123'); // email: original@example.test

        $this->submitSignup($client, 'fresh-username', 'original@example.test', 'NewPass123');

        self::assertResponseIsSuccessful();

        $em->clear();
        self::assertCount(
            1,
            $em->getRepository(\App\Entity\User::class)->findBy(['email' => 'original@example.test']),
            'no second account must be created for a duplicate email',
        );
        self::assertNull(
            $em->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'fresh-username']),
            'the rejected signup must not leave a partial account behind',
        );
    }

    /**
     * One end-to-end confirmation that the real controller enforces the
     * registration password-length rule - not the full matrix, which
     * UserTypeValidationTest already covers against the form in isolation.
     */
    public function testTooShortPasswordIsRejectedAtSignup(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);

        $this->submitSignup($client, 'newrenter', 'newrenter@example.test', 'short');

        // AbstractController::render() auto-sets 422 for a submitted,
        // invalid Form parameter (confirmed in this project's
        // test/reservations work).
        self::assertResponseStatusCodeSame(422);

        $em->clear();
        self::assertNull($em->getRepository(\App\Entity\User::class)->findOneBy(['username' => 'newrenter']));
    }

    public function testAlreadyAuthenticatedUserIsRedirectedAwayFromSignup(): void
    {
        $client = $this->bootWithFixtures();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $user = $this->makeUser($em, 'loggedin', 'SomePass123');

        $client->loginUser($user);
        $client->request('GET', '/signup');

        self::assertResponseRedirects('/');
    }
}
