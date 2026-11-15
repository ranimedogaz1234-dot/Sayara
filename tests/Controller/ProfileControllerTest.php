<?php

namespace App\Tests\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Regression coverage for the profile edit form.
 *
 * UserType maps its password field directly onto the User entity, and that
 * entity is the same object the security token keeps in the session. If a
 * request leaves the raw password on it, the session holds a value that no
 * longer matches the stored hash, so the next request logs the user out.
 */
class ProfileControllerTest extends WebTestCase
{
    private const ORIGINAL_PASSWORD = 'OriginalPass123';

    private function makeUser(
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        string $username,
        string $password,
    ): User {
        $user = new User();
        $user->setUsername($username);
        $user->setNom('Test '.$username);
        $user->setEmail($username.'@example.test');
        $user->setRole('ROLE_USER');
        $user->setPassword($hasher->hashPassword($user, $password));

        $em->persist($user);
        $em->flush();

        return $user;
    }

    /**
     * @return array{0: \Symfony\Bundle\FrameworkBundle\KernelBrowser, 1: EntityManagerInterface, 2: UserPasswordHasherInterface, 3: User}
     */
    private function bootWithUsers(): array
    {
        $client = static::createClient();
        $em = static::getContainer()->get(EntityManagerInterface::class);
        $hasher = static::getContainer()->get(UserPasswordHasherInterface::class);

        // Start from a known state without touching the development database.
        $em->createQuery('DELETE FROM '.\App\Entity\Reservation::class)->execute();
        $em->createQuery('DELETE FROM '.User::class)->execute();

        $victim = $this->makeUser($em, $hasher, 'victim', self::ORIGINAL_PASSWORD);
        $this->makeUser($em, $hasher, 'taken', 'OtherPass123');

        return [$client, $em, $hasher, $victim];
    }

    /**
     * The uniqueness check returns early. That path must not leave the raw
     * password on the session-backed user, which would silently sign them out.
     */
    public function testRejectedProfileEditKeepsTheUserSignedIn(): void
    {
        [$client, $em, $hasher, $victim] = $this->bootWithUsers();
        $client->loginUser($victim);

        $crawler = $client->request('GET', '/profile/edit');
        self::assertResponseIsSuccessful();

        $form = $crawler->filter('form')->form();
        $form['user[username]'] = 'taken';          // collides, so the controller bails out
        $form['user[password]'] = 'BrandNewPass123';
        $client->submit($form);

        // The form is re-rendered with an error rather than redirecting.
        self::assertResponseIsSuccessful();

        // The decisive assertion: the next request must still be authenticated.
        // Before the fix the session carried the plaintext password, the user
        // provider saw it diverge from the stored hash and dropped the session,
        // redirecting to /login.
        $client->request('GET', '/profile');
        self::assertResponseIsSuccessful();

        // The stored credentials must be untouched by the rejected edit.
        $em->clear();
        $stored = $em->getRepository(User::class)->findOneBy(['username' => 'victim']);
        self::assertNotNull($stored);
        self::assertTrue($hasher->isPasswordValid($stored, self::ORIGINAL_PASSWORD));
        self::assertSame('victim', $stored->getUsername(), 'the rejected username must not be saved');
    }

    /**
     * The edit form invites an empty password field ("Laisser vide pour ne pas
     * changer"). This was originally skipped here: User::$password carried
     * Assert\Length(min: 8) with no group restriction, so it ran on every
     * submission and rejected the empty string. fix/profile-validation
     * (merged into main) scoped Length to the 'registration' and
     * 'password_change' groups, activated only when a password was actually
     * submitted, which fixes exactly this. Un-skipped and confirmed passing.
     */
    public function testEmptyPasswordFieldKeepsTheCurrentPassword(): void
    {
        [$client, $em, $hasher, $victim] = $this->bootWithUsers();
        $client->loginUser($victim);

        $crawler = $client->request('GET', '/profile/edit');
        $form = $crawler->filter('form')->form();
        $form['user[nom]'] = 'Renamed Victim';
        $form['user[password]'] = '';
        $client->submit($form);

        self::assertResponseRedirects('/profile');

        $em->clear();
        $stored = $em->getRepository(User::class)->findOneBy(['username' => 'victim']);
        self::assertSame('Renamed Victim', $stored->getNom(), 'the profile edit should still save');
        self::assertTrue(
            $hasher->isPasswordValid($stored, self::ORIGINAL_PASSWORD),
            'an empty password field must leave the password unchanged',
        );
    }

    /**
     * Behaviour preservation: a new password is stored hashed, never in clear.
     */
    public function testNewPasswordIsStoredHashed(): void
    {
        [$client, $em, $hasher, $victim] = $this->bootWithUsers();
        $client->loginUser($victim);

        $newPassword = 'BrandNewPass123';

        $crawler = $client->request('GET', '/profile/edit');
        $form = $crawler->filter('form')->form();
        $form['user[password]'] = $newPassword;
        $client->submit($form);

        self::assertResponseRedirects('/profile');

        $em->clear();
        $stored = $em->getRepository(User::class)->findOneBy(['username' => 'victim']);
        self::assertNotSame($newPassword, $stored->getPassword(), 'the password must not be stored in clear text');
        self::assertTrue($hasher->isPasswordValid($stored, $newPassword), 'the new password should work');
        self::assertFalse($hasher->isPasswordValid($stored, self::ORIGINAL_PASSWORD), 'the old password should stop working');
    }
}
